<?php

use App\Models\Order;
use App\Models\OrderShop;
use App\Models\User;
use App\Services\OrderShopPurchaseConfirmationService;
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
    $service = $payload['operation'] === 'shared' ? SharedOrderShopPurchaseService::class : OrderShopPurchaseConfirmationService::class;
    $purchase = app($service)->record(User::findOrFail($payload['actor_id']), Order::findOrFail($payload['order_id']),
        OrderShop::findOrFail($payload['shop_id']), $payload['data'],
        UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')));
    $result = ['status' => 'success', 'id' => $purchase->id];
} catch (Throwable $exception) {
    $result = ['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
}
file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
