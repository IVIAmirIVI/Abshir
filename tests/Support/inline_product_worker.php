<?php

use App\Models\Order;
use App\Models\OrderShop;
use App\Models\User;
use App\Services\InlineOrderProductService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestDatabaseSafety;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertSafe($application);
[$script, $payloadPath, $readyPath, $goPath, $resultPath] = $argv;
$payload = json_decode(file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);
DB::select("SELECT set_config('application_name', ?, false)", [$payload['worker_name']]);
file_put_contents($readyPath, 'ready');
$deadline = microtime(true) + 20;
while (! file_exists($goPath)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Inline product start barrier timed out.');
    }
    usleep(10_000);
}
try {
    $product = app(InlineOrderProductService::class)->create(User::findOrFail($payload['actor_id']),
        Order::findOrFail($payload['order_id']), OrderShop::findOrFail($payload['order_shop_id']), $payload['data']);
    $result = ['status' => 'success', 'product_id' => $product->id];
} catch (ValidationException $exception) {
    $result = ['status' => 'validation', 'errors' => $exception->errors()];
} catch (Throwable $exception) {
    $result = ['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
}
file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
