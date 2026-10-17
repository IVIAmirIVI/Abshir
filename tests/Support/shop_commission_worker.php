<?php

use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderOperationalStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Services\InvoiceCollectionService;
use App\Services\OrderStatusService;
use App\Services\ShopCommissionAccountingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestDatabaseSafety;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertSafe($application);
$job = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
DB::select("SELECT set_config('application_name', ?, false)", [$job['tag']]);
try {
    $actor = User::findOrFail($job['actor']);
    $id = match ($job['operation']) {
        'payment' => app(ShopCommissionAccountingService::class)->recordPayment($actor, Shop::findOrFail($job['shop']), $job['account'], $job['data'])->id,
        'collect' => app(InvoiceCollectionService::class)->record($actor, Invoice::findOrFail($job['invoice']), InvoiceCollectionMethod::Cash, $job['amount'], $job['currency'], now())->id,
        'deliver' => (function () use ($actor, $job): int {
            app(OrderStatusService::class)->transition($actor, Order::findOrFail($job['order']), OrderOperationalStatus::Delivered, null);

            return $job['order'];
        })(),
    };
    echo json_encode(['status' => 'success', 'id' => $id], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode(['status' => 'validation', 'errors' => $exception->errors()], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
