<?php

namespace Tests\Support;

use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainBenefitReview;
use App\Models\Currency;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainBenefitRequestService;
use App\Services\CaptainBenefitReviewService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait CaptainBenefitFixtures
{
    protected function benefitContext(string $kind = 'BONUS', bool $evidence = false, bool $employeeCreator = false): array
    {
        Storage::fake('captain_financial_evidence');
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['code' => fake()->unique()->regexify('[A-Z]{3}'), 'name' => 'Test Currency', 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $captain = Captain::factory()->create(['home_branch_id' => $branch->id]);
        $captain->user->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        $creator = User::factory()->create();
        $reviewer = User::factory()->create();
        foreach ([$creator, $reviewer] as $user) {
            $user->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        }
        $employee = User::factory()->create();
        if ($employeeCreator) {
            $creator->roles()->sync([Role::where('code', 'ORDER_EMPLOYEE')->sole()->id]);
            $creator->branches()->attach($branch);
        }
        $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $employee->branches()->attach($branch);
        $data = ['request_key' => (string) Str::uuid(), 'kind' => $kind, 'branch_id' => $branch->id, 'currency_id' => $currency->id,
            'amount' => '20', 'description' => 'Manual operational benefit'];
        if ($kind === 'EXPENSE') {
            $data += ['category' => 'TRANSPORT', 'expense_date' => today()->toDateString()];
        }
        $proposal = app(CaptainBenefitRequestService::class)->save($creator, $captain, $data, $evidence ? $this->benefitImage() : null);

        return compact('currency', 'branch', 'captain', 'creator', 'reviewer', 'employee', 'proposal');
    }

    protected function benefitAction(array $c, string $action, ?User $actor = null, ?string $reason = null): CaptainBenefitReview
    {
        return app(CaptainBenefitReviewService::class)->act($actor ?? ($action === 'SUBMIT' ? $c['creator'] : $c['reviewer']), $c['captain'], $c['proposal'],
            ['request_key' => (string) Str::uuid(), 'action' => $action, 'reason' => $reason]);
    }

    protected function benefitImage(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    }
}
