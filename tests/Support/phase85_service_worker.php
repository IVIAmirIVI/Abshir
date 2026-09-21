<?php

use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderOperationalStatus;
use App\Models\Captain;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderShop;
use App\Models\User;
use App\Services\CaptainRatingService;
use App\Services\InvoiceService;
use App\Services\OrderCancellationService;
use App\Services\OrderShopReceiptService;
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
        'cancel_after' => app(OrderCancellationService::class)->cancel($actor, $order, OrderCancellationClassification::AfterPurchase, OrderCancellationReason::CustomerCancelled, 'Concurrent cancellation'),
        'deliver' => app(OrderStatusService::class)->transition($actor, $order, OrderOperationalStatus::Delivered, 'Concurrent delivery'),
        'issue' => app(InvoiceService::class)->issue($actor, $order, Invoice::query()->findOrFail($payload['invoice_id'])),
        'receipt' => app(OrderShopReceiptService::class)->upload($actor, $order, OrderShop::query()->findOrFail($payload['order_shop_id']), receiptUpload($temporaryUpload), 'Concurrent receipt'),
        'rating' => app(CaptainRatingService::class)->recordManual($actor, $order, Captain::query()->findOrFail($payload['captain_id']), $payload['rating']),
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
