<?php

use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderOperationalStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderShop;
use App\Models\User;
use App\Services\InvoiceCollectionService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderStatusService;
use App\Services\SharedOrderShopPurchaseService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabaseSafety;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertSafe($app);
[$script, $payloadPath, $readyPath, $goPath, $resultPath] = $argv;
$payload = json_decode(file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);
DB::select("SELECT set_config('application_name', ?, false)", [$payload['tag']]);
file_put_contents($readyPath, 'ready');
while (! file_exists($goPath)) {
    usleep(10_000);
}
try {
    if ($payload['operation'] === 'collection') {
        $collection = app(InvoiceCollectionService::class)->record(User::findOrFail($payload['actor_id']),
            Invoice::findOrFail($payload['data']['invoice_id']), InvoiceCollectionMethod::from($payload['data']['method']),
            $payload['data']['amount'], $payload['data']['currency_id'], $payload['data']['collected_at'], null, $payload['data']['reference'] ?? null);
        $result = ['status' => 'success', 'id' => $collection->id];
    } elseif ($payload['operation'] === 'delivery') {
        app(OrderStatusService::class)->transition(User::findOrFail($payload['actor_id']), Order::findOrFail($payload['order_id']),
            OrderOperationalStatus::Delivered, null);
        $result = ['status' => 'success', 'id' => $payload['order_id']];
    } else {
        $service = $payload['operation'] === 'shared' ? SharedOrderShopPurchaseService::class : OrderShopPurchaseConfirmationService::class;
        $purchase = app($service)->record(User::findOrFail($payload['actor_id']), Order::findOrFail($payload['order_id']),
            OrderShop::findOrFail($payload['shop_id']), $payload['data'],
            UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')));
        $result = ['status' => 'success', 'id' => $purchase->id];
    }
} catch (Throwable $exception) {
    $result = ['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
}
file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
