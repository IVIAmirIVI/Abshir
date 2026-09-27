<?php

use App\Models\BillPaymentFeeRule;
use App\Models\Order;
use App\Models\OrderBillPaymentRequest;
use App\Models\User;
use App\Services\BillPaymentFeeRuleService;
use App\Services\OrderBillPaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
[$script, $payloadPath, $readyPath, $goPath, $resultPath] = $argv;
$payload = json_decode(file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);
DB::select("SELECT set_config('application_name', ?, false)", [$payload['tag']]);
file_put_contents($readyPath, 'ready');
while (! file_exists($goPath)) {
    usleep(10_000);
}

try {
    $complete = fn () => app(OrderBillPaymentService::class)->complete(
        User::findOrFail($payload['actor_id']),
        Order::findOrFail($payload['order_id']),
        OrderBillPaymentRequest::findOrFail($payload['request_id']),
        $payload['data'],
        [UploadedFile::fake()->createWithContent($payload['evidence_name'] ?? 'proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='))],
    )->id;
    $mutateDelivery = fn (): int => DB::update(
        'UPDATE order_status_history SET changed_at = changed_at + make_interval(secs => 1) WHERE id = ?',
        [$payload['history_id']],
    );
    $hold = function () use ($payload): void {
        file_put_contents($payload['hold_path'], 'holding');
        $deadline = microtime(true) + 20;
        while (! file_exists($payload['release_path']) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        if (! file_exists($payload['release_path'])) {
            throw new RuntimeException('The delivery-race coordinator did not release the first transaction.');
        }
    };
    $id = match ($payload['operation']) {
        'complete' => $complete(),
        'complete_hold' => DB::transaction(function () use ($complete, $hold): int {
            $id = $complete();
            $hold();

            return $id;
        }),
        'mutate_delivery' => DB::transaction($mutateDelivery),
        'insert_payment' => DB::transaction(fn (): int => DB::table('order_bill_payments')->insertGetId($payload['payment_attributes'])),
        'mutate_delivery_hold' => DB::transaction(function () use ($mutateDelivery, $hold): int {
            $id = $mutateDelivery();
            $hold();

            return $id;
        }),
        'request' => app(OrderBillPaymentService::class)->request(
            User::findOrFail($payload['actor_id']),
            Order::findOrFail($payload['order_id']),
            $payload['currency_id'],
        )->id,
        'version' => app(BillPaymentFeeRuleService::class)->version(
            User::findOrFail($payload['actor_id']),
            BillPaymentFeeRule::findOrFail($payload['rule_id']),
            $payload['fee'],
            $payload['effective_from'] === 'NOW' ? null : $payload['effective_from'],
        )->id,
    };
    $result = ['status' => 'success', 'id' => $id];
} catch (Throwable $exception) {
    $result = ['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
}
file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
