<?php

use App\Enums\FinancialAccountKind;
use App\Exceptions\FinancialLedgerException;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialOwner;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\FinancialAccountService;
use App\Services\FinancialPostingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
Tests\Support\TestDatabaseSafety::assertSafe($application);

[$script, $payloadPath, $readyPath, $goPath, $resultPath] = $argv;
$payload = json_decode(file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);
if (isset($payload['worker_tag'])) {
    DB::select("SELECT set_config('application_name', ?, false)", [$payload['worker_tag']]);
}
file_put_contents($readyPath, 'ready');

$deadline = microtime(true) + 15;
while (! file_exists($goPath)) {
    if (microtime(true) >= $deadline) {
        file_put_contents($resultPath, json_encode(['status' => 'error', 'message' => 'Barrier timed out.'], JSON_THROW_ON_ERROR));
        exit(2);
    }
    usleep(10_000);
}

try {
    $result = match ($payload['operation']) {
        'account' => account($payload),
        'event' => event($payload),
        'reversal' => reversal($payload),
        'precision' => precision($payload),
        default => throw new InvalidArgumentException('Unknown financial ledger operation.'),
    };
} catch (ValidationException $exception) {
    $result = ['status' => 'validation_error', 'message' => $exception->getMessage()];
} catch (FinancialLedgerException $exception) {
    $result = ['status' => 'ledger_error', 'class' => $exception::class, 'message' => $exception->getMessage()];
} catch (Throwable $exception) {
    $result = ['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
}

file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));

function account(array $payload): array
{
    $owner = FinancialOwner::query()->findOrFail($payload['owner_id']);
    $currency = Currency::query()->findOrFail($payload['currency_id']);
    $account = app(FinancialAccountService::class)->getOrCreate($owner, $currency, FinancialAccountKind::from($payload['account_kind']));

    return ['status' => 'success', 'id' => $account->id];
}

function precision(array $payload): array
{
    $currency = Currency::findOrFail($payload['currency_id']);
    app(CurrencyService::class)->save(User::findOrFail($payload['actor_id']), $currency, [
        'code' => $currency->code, 'name' => $currency->name, 'decimal_places' => 2,
    ]);

    return ['status' => 'success'];
}

function event(array $payload): array
{
    $currency = Currency::query()->findOrFail($payload['currency_id']);
    $account = FinancialAccount::query()->findOrFail($payload['account_id']);
    $transaction = app(FinancialPostingService::class)->postEvent(
        $currency, $payload['source_type'], $payload['source_id'], $payload['event_type'], 1,
        CarbonImmutable::parse($payload['occurred_at']),
        [['account' => $account, 'direction' => 'INCREASE', 'amount' => $payload['amount']]],
        Branch::query()->findOrFail($payload['branch_id']),
    );

    return [
        'status' => 'success',
        'id' => $transaction->id,
        'transaction_status' => $transaction->status->value,
        'entry_count' => $transaction->entries->count(),
    ];
}

function reversal(array $payload): array
{
    $transaction = app(FinancialPostingService::class)->reverse(
        FinancialTransaction::query()->findOrFail($payload['transaction_id']),
        $payload['source_type'], $payload['source_id'], $payload['event_type'], 1,
        CarbonImmutable::parse($payload['occurred_at']), $payload['reason'],
    );

    return ['status' => 'success', 'id' => $transaction->id];
}
