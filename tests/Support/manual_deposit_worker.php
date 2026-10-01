<?php

use App\Enums\OrderOperationalStatus;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Order;
use App\Models\User;
use App\Services\CaptainDepositManualAdjustmentService;
use App\Services\CaptainDepositReceiptService;
use App\Services\OrderStatusService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
$job = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
foreach ($job['evidence_roots'] ?? [] as $disk => $root) {
    config(['filesystems.disks.'.$disk.'.root' => $root]);
}
DB::select("SELECT set_config('application_name',?,false)", [$job['tag']]);
file_put_contents($job['ready'], 'ready');
try {
    $id = DB::transaction(function () use ($job): int {
        $actor = User::findOrFail($job['actor_id']);
        $proof = UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $result = match ($job['operation']) {
            'manual' => app(CaptainDepositManualAdjustmentService::class)->record($actor, Captain::findOrFail($job['captain_id']), Branch::findOrFail($job['branch_id']), Currency::findOrFail($job['currency_id']), $job['data'], $proof)->id,
            'receipt' => app(CaptainDepositReceiptService::class)->record($actor, Captain::findOrFail($job['captain_id']), Branch::findOrFail($job['branch_id']), Currency::findOrFail($job['currency_id']), $job['data'], $proof)->id,
            'deliver' => (function () use ($actor, $job): int {
                $order = Order::findOrFail($job['order_id']);
                app(OrderStatusService::class)->transition($actor, $order, OrderOperationalStatus::Delivered, null);

                return $order->id;
            })(),
        };
        // Synchronization only: hold the real production service locks until observed.
        file_put_contents($job['held'], 'held');
        $deadline = microtime(true) + 20;
        while (! file_exists($job['release'])) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Worker release barrier timed out.');
            }
            usleep(10000);
        }

        return $result;
    });
    $response = ['status' => 'success', 'id' => $id];
} catch (Throwable $e) {
    $response = ['status' => 'error', 'class' => $e::class, 'message' => $e->getMessage()];
}
file_put_contents($job['result'], json_encode($response, JSON_THROW_ON_ERROR));
