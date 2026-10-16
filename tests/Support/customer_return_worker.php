<?php

use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceFinancialCorrectionKind;
use App\Models\Customer;
use App\Models\CustomerReturnCase;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerCreditCashPayoutService;
use App\Services\CustomerCreditService;
use App\Services\CustomerReturnService;
use App\Services\InvoiceFinancialCorrectionService;
use App\Services\MixedCustomerCreditService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestDatabaseSafety;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertSafe($app);
$job = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
DB::select("SELECT set_config('application_name', ?, false)", [$job['tag']]);
config(['filesystems.disks.customer_refunds.root' => $job['storage_root']]);
try {
    $service = app(CustomerReturnService::class);
    $actor = User::findOrFail($job['actor']);
    if ($job['operation'] === 'cash-credit') {
        $file = UploadedFile::fake()->createWithContent('receipt.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=').$job['proof']);
        $record = app(CustomerCreditCashPayoutService::class)->record($actor, Customer::findOrFail($job['customer']), $job['data'], $file);
    } elseif ($job['operation'] === 'mixed') {
        $record = app(MixedCustomerCreditService::class)->record($actor, Invoice::findOrFail($job['invoice']), InvoiceCollectionMethod::from($job['method']), $job['data']);
    } elseif ($job['operation'] === 'credit') {
        $record = app(CustomerCreditService::class)->grant($actor, Customer::findOrFail($job['customer']), $job['data']);
    } elseif ($job['operation'] === 'correction') {
        $record = app(InvoiceFinancialCorrectionService::class)->request($actor, Invoice::findOrFail($job['invoice']),
            InvoiceFinancialCorrectionKind::Reprice, $job['proposal'], 'Concurrent correction', 'No mixing with RET', $job['key']);
    } elseif ($job['operation'] === 'record') {
        $record = $service->record($actor, Order::findOrFail($job['order']), $job['data']);
    } else {
        $file = UploadedFile::fake()->createWithContent('receipt.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=').$job['proof']);
        $record = $service->move($actor, CustomerReturnCase::findOrFail($job['case']), $job['data'], $file);
    }
    echo json_encode(['status' => 'success', 'id' => $record->id], JSON_THROW_ON_ERROR);
} catch (ValidationException $e) {
    echo json_encode(['status' => 'validation', 'errors' => $e->errors()], JSON_THROW_ON_ERROR);
} catch (QueryException $e) {
    echo json_encode(['status' => ($e->errorInfo[0] === '23514' && str_contains($e->getMessage(), 'RET case already exists')) ? 'validation' : 'error', 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
