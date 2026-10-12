<?php

use App\Models\Captain;
use App\Models\CaptainBenefitRequest;
use App\Models\Currency;
use App\Models\User;
use App\Services\CaptainBenefitRequestService;
use App\Services\CaptainBenefitReviewService;
use App\Services\CaptainReceivablePayoutService;
use Illuminate\Contracts\Console\Kernel;
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
config(['filesystems.disks.captain_financial_evidence.root' => $job['storage_root']]);
try {
    $actor = User::findOrFail($job['actor']);
    $proposal = CaptainBenefitRequest::findOrFail($job['proposal']);
    $captain = Captain::findOrFail($proposal->captain_id);
    if ($job['operation'] === 'draft') {
        $id = app(CaptainBenefitRequestService::class)->save($actor, $captain, $job['data'])->id;
    } elseif ($job['operation'] === 'review') {
        $id = app(CaptainBenefitReviewService::class)->act($actor, $captain, $proposal, $job['data'])->id;
    } else {
        $image = UploadedFile::fake()->createWithContent('evidence.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $id = app(CaptainReceivablePayoutService::class)->record($actor, $captain, $proposal->branch,
            Currency::findOrFail($proposal->currency_id), $job['data'], $job['allocations'], $image)->id;
    }
    echo json_encode(['status' => 'success', 'id' => $id], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode(['status' => 'validation', 'errors' => $exception->errors()], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
