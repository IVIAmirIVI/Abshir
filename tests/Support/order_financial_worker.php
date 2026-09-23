<?php

use App\Enums\InvoiceCollectionMethod;
use App\Models\Invoice;
use App\Models\InvoiceCollection;
use App\Models\Order;
use App\Models\User;
use App\Services\InvoiceCollectionService;
use App\Services\OrderFinancialPostingService;
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
    $service = app(OrderFinancialPostingService::class);
    $result = match ($payload['operation']) {
        'order' => $service->postOrderDerivedIfReady(Order::findOrFail($payload['id']))->count(),
        'cash' => $service->postCashCollectionCustody(InvoiceCollection::findOrFail($payload['id']))?->id,
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
