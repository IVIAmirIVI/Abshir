<?php

namespace Tests\Support;

use App\Enums\FinancialAccountKind;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainDepositManualAdjustmentEvidenceService;
use App\Services\CaptainDepositManualAdjustmentService;
use App\Services\CaptainDepositReceiptService;
use App\Services\FinancialBalanceQueryService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait ManualDepositFixtures
{
    protected function manualFixture(): array
    {
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['name' => 'USD', 'code' => 'USD', 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $employee = User::factory()->create();
        $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $employee->branches()->attach($branch);
        $captain = Captain::factory()->create(['home_branch_id' => $branch->id]);
        Storage::fake(CaptainDepositManualAdjustmentEvidenceService::DISK);
        Storage::fake('captain_financial_evidence');

        return compact('admin', 'employee', 'captain', 'branch', 'currency');
    }

    protected function manualData(string $amount = '25', string $direction = 'INCREASE'): array
    {
        return ['amount' => $amount, 'direction' => $direction, 'reason' => 'Exceptional administrative correction',
            'request_key' => (string) Str::uuid(), 'occurred_at' => now()->subSecond()->format('Y-m-d H:i:s.uP')];
    }

    protected function manualProof(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    }

    protected function adjust(array $f, ?array $data = null): object
    {
        return app(CaptainDepositManualAdjustmentService::class)->record($f['admin'], $f['captain'], $f['branch'], $f['currency'], $data ?? $this->manualData(), $this->manualProof());
    }

    protected function deposit(array $f, string $amount): object
    {
        return app(CaptainDepositReceiptService::class)->record($f['employee'], $f['captain'], $f['branch'], $f['currency'],
            ['amount' => $amount, 'request_key' => (string) Str::uuid(), 'occurred_at' => now()->subSecond()->format('Y-m-d H:i:s.uP')], $this->manualProof());
    }

    protected function depositBalance(array $f): string
    {
        return app(FinancialBalanceQueryService::class)->captainBranchBalance($f['captain'], $f['branch'], $f['currency'], FinancialAccountKind::CaptainDeposit);
    }
}
