<?php

use App\Models\Order;
use App\Models\OrderShop;
use App\Models\User;
use App\Services\OrderCaptainSelectionService;
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
file_put_contents($readyPath, (string) DB::scalar('SELECT pg_backend_pid()'));
$deadline = microtime(true) + 20;
while (! file_exists($goPath) && microtime(true) < $deadline) {
    usleep(10_000);
}
try {
    if (! file_exists($goPath)) {
        throw new RuntimeException('Synchronization barrier timed out.');
    }
    $actor = User::findOrFail($payload['actor_id']);
    $order = Order::findOrFail($payload['order_id']);
    $data = $payload['data'];
    if ($payload['operation'] === 'selection') {
        $source = app(OrderCaptainSelectionService::class)->select($actor, $order,
            $data['captain_ids'], $data['collector_id'], $data['request_key'], $data['reason'] ?? null);
    } elseif ($payload['operation'] === 'purchase') {
        $source = app(SharedOrderShopPurchaseService::class)->record($actor, $order,
            OrderShop::findOrFail($payload['shop_id']), $data,
            UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')));
    } else {
        throw new RuntimeException('Unsupported test operation.');
    }
    $result = ['status' => 'success', 'id' => $source->id];
} catch (Throwable $exception) {
    $result = ['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
}
file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
