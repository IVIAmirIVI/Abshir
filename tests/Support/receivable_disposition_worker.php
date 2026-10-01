<?php

use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\InvoiceFinancialCorrection;
use App\Models\User;
use App\Services\CaptainReceivablePayoutService;
use App\Services\InvoiceFinancialCorrectionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
$job = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
DB::select("SELECT set_config('application_name',?,false)", [$job['tag']]);
file_put_contents($job['ready'], 'ready');
try {
    $result = DB::transaction(function () use ($job): int {
        $actor = User::findOrFail($job['actor_id']);
        $result = match ($job['operation']) {
            'prepare' => app(InvoiceFinancialCorrectionService::class)->prepare($actor, InvoiceFinancialCorrection::findOrFail($job['correction_id']))->id,
            'finalize' => app(InvoiceFinancialCorrectionService::class)->finalize($actor, InvoiceFinancialCorrection::findOrFail($job['correction_id']))->id,
            'payout' => app(CaptainReceivablePayoutService::class)->record($actor, Captain::findOrFail($job['captain_id']),
                Branch::findOrFail($job['branch_id']), Currency::findOrFail($job['currency_id']), $job['data'], $job['allocations'],
                UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')))->id,
        };
        // Hold only the real service's acquired locks for deterministic contention observation.
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
    $response = ['status' => 'success', 'id' => $result];
} catch (Throwable $exception) {
    $response = ['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
}
file_put_contents($job['result'], json_encode($response, JSON_THROW_ON_ERROR));
