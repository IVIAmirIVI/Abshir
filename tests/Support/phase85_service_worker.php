<?php

use App\Enums\InvoiceFinancialCorrectionKind;
use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderOperationalStatus;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceFinancialCorrection;
use App\Models\Order;
use App\Models\OrderShop;
use App\Models\User;
use App\Services\CaptainRatingService;
use App\Services\CaptainReceivablePayoutService;
use App\Services\CustomerRefundService;
use App\Services\InvoiceFinancialCorrectionService;
use App\Services\InvoiceService;
use App\Services\OrderCancellationFinalizationService;
use App\Services\OrderCancellationIntentService;
use App\Services\OrderCancellationService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderShopReturnService;
use App\Services\OrderStatusService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

[$script, $payloadPath, $readyPath, $goPath, $resultPath] = $argv;
$payload = json_decode(file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);
file_put_contents($readyPath, 'ready');

$deadline = microtime(true) + 15;
while (! file_exists($goPath)) {
    if (microtime(true) >= $deadline) {
        file_put_contents($resultPath, json_encode(['status' => 'error', 'class' => RuntimeException::class, 'message' => 'Concurrency start barrier timed out.'], JSON_THROW_ON_ERROR));
        exit(2);
    }

    usleep(10_000);
}

$temporaryUpload = null;

try {
    $actor = User::query()->findOrFail($payload['actor_id']);
    $order = Order::query()->findOrFail($payload['order_id']);

    match ($payload['operation']) {
        'cancel_before' => app(OrderCancellationService::class)->cancel($actor, $order, OrderCancellationClassification::BeforePurchase, OrderCancellationReason::CustomerCancelled, 'Concurrent cancellation'),
        'cancel_after' => app(OrderCancellationIntentService::class)->create($actor, $order, OrderCancellationReason::CustomerCancelled, 'Concurrent cancellation', $payload['request_key']),
        'purchase' => app(OrderShopPurchaseConfirmationService::class)->record($actor, $order, OrderShop::findOrFail($payload['order_shop_id']), [
            'order_captain_id' => $payload['order_captain_id'], 'currency_id' => $payload['currency_id'],
            'amount' => '10.00', 'purchased_at' => now()->subMinute()->toIso8601String(),
            'request_key' => $payload['request_key'],
        ], receiptUpload($temporaryUpload)),
        'return' => app(OrderShopReturnService::class)->record($actor, $order, OrderShop::findOrFail($payload['order_shop_id']), [
            'returning_order_captain_id' => $payload['order_captain_id'], 'refund_amount' => '10.00',
            'returned_at' => now()->subSeconds(2)->toIso8601String(), 'refunded_at' => now()->toIso8601String(),
            'refund_method' => 'CASH', 'request_key' => $payload['request_key'],
        ], ['RETURN_AND_REFUND_PROOF' => receiptUpload($temporaryUpload)]),
        'finalize' => app(OrderCancellationFinalizationService::class)->finalize($actor, $order),
        'deliver' => app(OrderStatusService::class)->transition($actor, $order, OrderOperationalStatus::Delivered, 'Concurrent delivery'),
        'issue' => app(InvoiceService::class)->issue($actor, $order, Invoice::query()->findOrFail($payload['invoice_id'])),
        'invoice_replace' => app(InvoiceService::class)->replace($actor, $order,
            Invoice::query()->findOrFail($payload['invoice_id']), $payload['reason']),
        'receipt' => app(OrderShopReceiptService::class)->upload($actor, $order, OrderShop::query()->findOrFail($payload['order_shop_id']), receiptUpload($temporaryUpload), 'Concurrent receipt'),
        'rating' => app(CaptainRatingService::class)->recordManual($actor, $order, Captain::query()->findOrFail($payload['captain_id']), $payload['rating']),
        'correction_request' => app(InvoiceFinancialCorrectionService::class)->request($actor,
            Invoice::query()->findOrFail($payload['invoice_id']), InvoiceFinancialCorrectionKind::from($payload['kind']),
            $payload['proposal'], $payload['reason'], $payload['explanation'], $payload['request_key']),
        'correction_prepare' => app(InvoiceFinancialCorrectionService::class)->prepare($actor,
            InvoiceFinancialCorrection::query()->findOrFail($payload['correction_id'])),
        'correction_finalize' => app(InvoiceFinancialCorrectionService::class)->finalize($actor,
            InvoiceFinancialCorrection::query()->findOrFail($payload['correction_id'])),
        'correction_refund' => app(CustomerRefundService::class)->record($actor,
            InvoiceFinancialCorrection::query()->findOrFail($payload['correction_id']),
            $payload['request_key'], $payload['refunded_at'] ?? now(), $payload['reference'], receiptUpload($temporaryUpload)),
        'receivable_payout' => app(CaptainReceivablePayoutService::class)->record($actor,
            Captain::query()->findOrFail($payload['captain_id']), Branch::query()->findOrFail($payload['branch_id']),
            Currency::query()->findOrFail($payload['currency_id']),
            ['request_key' => $payload['request_key'], 'amount' => $payload['amount'],
                'occurred_at' => $payload['occurred_at'], 'reference' => $payload['reference']],
            [['financial_entry_id' => $payload['financial_entry_id'], 'amount' => $payload['amount']]],
            receiptUpload($temporaryUpload)),
        default => throw new InvalidArgumentException('Unknown Phase 8.5 concurrency operation.'),
    };

    $result = ['status' => 'success'];
} catch (ValidationException $exception) {
    $result = ['status' => 'validation', 'errors' => $exception->errors()];
} catch (Throwable $exception) {
    $result = ['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
} finally {
    if ($temporaryUpload && file_exists($temporaryUpload)) {
        unlink($temporaryUpload);
    }
}

file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));

function receiptUpload(?string &$temporaryUpload): UploadedFile
{
    $temporaryUpload = tempnam(sys_get_temp_dir(), 'abshir-concurrent-receipt-');
    file_put_contents($temporaryUpload, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

    return new UploadedFile($temporaryUpload, 'receipt.png', null, null, true);
}
