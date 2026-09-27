<?php

use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderOperationalStatus;
use App\Models\Area;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\CorrectedOrderFinancialService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderFinancialBackfillService;
use App\Services\OrderFinancialSemanticsCorrectionService;
use App\Services\OrderStatusService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
[$script, $payloadPath, $readyPath, $goPath, $resultPath] = $argv;
$payload = json_decode(file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);
DB::select("SELECT set_config('application_name', ?, false)", [$payload['worker_tag']]);
file_put_contents($readyPath, 'ready');
while (! file_exists($goPath)) {
    usleep(10_000);
}

try {
    $result = match ($payload['operation']) {
        'post' => count(app(CorrectedOrderFinancialService::class)->postIfReady(Order::findOrFail($payload['id']))),
        'backfill' => app(OrderFinancialBackfillService::class)->run(true, fn (string $line) => null),
        'correct' => app(OrderFinancialSemanticsCorrectionService::class)->correct(Order::findOrFail($payload['id']), true)['state'],
        'deliver' => (function () use ($payload): int {
            app(OrderStatusService::class)->transition(User::findOrFail($payload['actor_id']), Order::findOrFail($payload['id']), OrderOperationalStatus::Delivered, null);

            return $payload['id'];
        })(),
        'issue' => app(InvoiceService::class)->issue(User::findOrFail($payload['actor_id']), Order::findOrFail($payload['order_id']), Invoice::findOrFail($payload['id']))->id,
        'reprice' => app(OrderDeliveryPricingService::class)->price(User::findOrFail($payload['actor_id']), Order::findOrFail($payload['id']), Area::findOrFail($payload['origin_id']))->id,
        'direct-allocation-update' => DB::table('order_delivery_allocations')->where('id', $payload['id'])->update([
            'captain_entitlement' => '4.0000', 'company_collectible' => '1.0000',
        ]),
        'collect' => app(InvoiceCollectionService::class)->record(
            User::findOrFail($payload['actor_id']),
            Invoice::findOrFail($payload['id']),
            InvoiceCollectionMethod::Cash,
            $payload['amount'],
            $payload['currency_id'],
            $payload['collected_at'],
        )->id,
    };
    $response = ['status' => 'success', 'result' => $result];
} catch (Throwable $exception) {
    $response = ['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
}
file_put_contents($resultPath, json_encode($response, JSON_THROW_ON_ERROR));
