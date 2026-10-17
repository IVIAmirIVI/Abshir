<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\InvoiceCollectionMethod;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CustomerCreditNotice;
use App\Services\CustomerCreditService;
use App\Services\CustomerReturnService;
use App\Services\FinancialAccountService;
use App\Services\FinancialPostingService;
use App\Services\InternalNoticeService;
use App\Services\InvoiceCollectionService;
use Brick\Math\BigDecimal;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerReturnFixtures;
use Tests\TestCase;

class CustomerCreditTest extends TestCase
{
    use CustomerReturnFixtures, RefreshDatabase;

    private function master(): array
    {
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['code' => 'SYP', 'name' => 'Syrian Pound', 'decimal_places' => 0]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $area = Area::factory()->for($branch)->create();
        $customer = Customer::create(['name' => 'UAT CREDIT customer']);
        $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'UAT']);
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $actor->branches()->attach($branch);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());

        return compact('currency', 'branch', 'customer', 'actor', 'admin');
    }

    private function creditData(array $c, string $amount = '30'): array
    {
        return ['request_key' => (string) str()->uuid(), 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
            'reason' => 'OVERCHARGE', 'notes' => 'Documented previous delivery overcharge', 'reference' => (string) str()->uuid(),
            'amount' => $amount, 'occurred_at' => now()->subMinute()->format('Y-m-d H:i:s.uP')];
    }

    private function allowCredit(User $actor): void
    {
        $role = Role::firstOrCreate(['code' => 'CREDIT_SUPERVISOR'], ['name' => 'Credit supervisor']);
        $role->permissions()->sync(Permission::whereIn('code', ['customer_credits.view', 'customer_credits.grant', 'customer_credits.redeem', 'customer_credits.payout'])->pluck('id'));
        $actor->roles()->syncWithoutDetaching([$role->id]);
    }

    public function test_authorized_supervisor_grants_thirty_syp_once_without_cash_or_captain_postings(): void
    {
        $c = $this->master();
        $s = app(CustomerCreditService::class);
        $data = $this->creditData($c);
        $entry = $s->grant($c['actor'], $c['customer'], $data);
        $this->assertSame($entry->id, $s->grant($c['actor'], $c['customer'], $data)->id);
        $this->assertSame('30.0000', $s->balances($c['actor'], $c['customer'])->sole()->available);
        $this->assertDatabaseCount('customer_credit_entries', 1);
        $this->assertDatabaseCount('financial_entries', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $notice = app(InternalNoticeService::class)->visible($c['admin'])->where('type', CustomerCreditNotice::class)->sole();
        $this->assertStringContainsString('30.0000 SYP', $notice->data['body']);
        $this->assertSame(route('customers.show', $c['customer']), app(InternalNoticeService::class)->destination($c['admin'], $notice));
        $this->actingAs($c['actor'])->get(route('customers.show', $c['customer']))->assertOk()->assertSee(__('credit.grant'))->assertSee(__('credit.workflow_help'));
        $other = $data;
        $other['request_key'] = (string) str()->uuid();
        $this->assertThrows(fn () => $s->grant($c['actor'], $c['customer'], $other), ValidationException::class);
        $data['amount'] = '31';
        $this->assertThrows(fn () => $s->grant($c['actor'], $c['customer'], $data), ValidationException::class);
    }

    public function test_employee_has_only_approved_credit_permissions_and_cross_branch_access_is_rejected(): void
    {
        $c = $this->master();
        foreach (['view', 'grant', 'redeem', 'payout'] as $action) {
            $this->assertTrue($c['actor']->can('customer_credits.'.$action));
            $this->assertTrue($c['admin']->can('customer_credits.'.$action));
        }
        $this->assertFalse($c['actor']->can('captains.update'));
        $this->assertFalse($c['actor']->can('captain_bonuses.approve'));
        $this->assertFalse($c['actor']->can('captain_expenses.approve'));
        $this->actingAs($c['actor']);
        $bad = $this->creditData($c);
        $bad['branch_id'] = Branch::factory()->create()->id;
        $this->post(route('customers.credits.store', $c['customer']), $bad)->assertForbidden();
        $foreign = Customer::create(['name' => 'Foreign customer']);
        $this->post(route('customers.credits.store', $foreign), $this->creditData($c))->assertForbidden();
        $captain = User::factory()->create();
        $captain->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        $this->actingAs($captain)->post(route('customers.credits.store', $c['customer']), $this->creditData($c))->assertForbidden();
        $this->assertDatabaseCount('customer_credit_entries', 0);
    }

    public function test_balances_are_separate_by_currency_and_authorized_branch(): void
    {
        $c = $this->master();
        $this->allowCredit($c['actor']);
        $s = app(CustomerCreditService::class);
        $s->grant($c['admin'], $c['customer'], $this->creditData($c));
        $usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'decimal_places' => 2]);
        $data = $this->creditData($c, '12.34');
        $data['currency_id'] = $usd->id;
        $s->grant($c['admin'], $c['customer'], $data);
        $foreign = Branch::factory()->create();
        $data = $this->creditData($c, '40');
        $data['branch_id'] = $foreign->id;
        $s->grant($c['admin'], $c['customer'], $data);
        $balances = $s->balances($c['actor'], $c['customer']);
        $this->assertCount(2, $balances);
        $this->assertSame('30.0000', $balances->firstWhere('currency_code', 'SYP')->available);
        $this->assertSame('12.3400', $balances->firstWhere('currency_code', 'USD')->available);
        $this->assertCount(3, $s->balances($c['admin'], $c['customer']));
        $this->assertCount(0, $s->balances($c['actor'], $c['customer'], $foreign->id));
    }

    #[DataProvider('versions')]
    public function test_return_credit_is_atomic_exclusive_and_does_not_fund_captain_debt(int $count): void
    {
        $c = $this->sold($count);
        $this->allowCredit($c['actor']);
        $before = DB::table('financial_entries')->orderBy('id')->get()->toJson();
        $data = $this->data($c) + ['compensation_method' => 'CREDIT'];
        $data['responsibilities'] = [['party' => 'CAPTAIN', 'order_captain_id' => $c['assignments'][$count - 1]->id, 'amount' => '80']];
        $s = app(CustomerReturnService::class);
        $case = $s->record($c['actor'], $c['order'], $data);
        $this->assertSame($case->id, $s->record($c['actor'], $c['order'], $data)->id);
        $this->assertDatabaseCount('customer_credit_entries', 1);
        $this->assertSame('80.0000', app(CustomerCreditService::class)->balances($c['actor'], $c['order']->customer)->sole()->available);
        $summary = $s->summary($c['actor'], $c['order'])->sole();
        $this->assertSame('80.0000', $summary->credit_granted);
        $this->assertSame('0.0000', $summary->unpaid_compensation);
        $this->assertSame('0.0000', $summary->responsibilities->sole()->funded_amount);
        $this->assertSame('0.0000', $summary->responsibilities->sole()->outstanding);
        $this->assertThrows(fn () => $s->move($c['actor'], $case, $this->movement($c, '80'), $this->proof('no double cash')), ValidationException::class);
        $this->assertThrows(fn () => $s->move($c['actor'], $case, $this->movement($c, '1', 'RECOVERY') + ['responsibility_id' => $case->responsibilities()->sole()->id], $this->proof('no unfunded debt')), ValidationException::class);
        $this->assertSame($before, DB::table('financial_entries')->orderBy('id')->get()->toJson());
        $this->actingAs($c['actor'])->get(route('orders.workflow', $c['order']))->assertOk()->assertSee(__('credit.credit_compensation'))->assertSee(__('credit.title'));
        $data['compensation_method'] = 'CASH';
        $this->assertThrows(fn () => $s->record($c['actor'], $c['order'], $data), ValidationException::class);
    }

    public static function versions(): array
    {
        return [[1], [2]];
    }

    public function test_failed_grant_notification_rolls_back_return_and_credit_together(): void
    {
        $c = $this->sold();
        $this->allowCredit($c['actor']);
        DB::unprepared("CREATE FUNCTION credit_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''credit notice failure''; END'; CREATE TRIGGER credit_test_fail BEFORE INSERT ON notifications FOR EACH ROW EXECUTE FUNCTION credit_test_fail()");
        try {
            $this->assertThrows(fn () => app(CustomerReturnService::class)->record($c['actor'], $c['order'], $this->data($c) + ['compensation_method' => 'CREDIT']), QueryException::class);
        } finally {
            DB::unprepared('DROP TRIGGER credit_test_fail ON notifications; DROP FUNCTION credit_test_fail()');
        }
        $this->assertDatabaseCount('customer_credit_entries', 0);
        $this->assertDatabaseCount('customer_return_cases', 0);
    }

    public function test_return_credit_requires_specific_permission_and_cannot_follow_cash_compensation(): void
    {
        $c = $this->sold();
        Role::where('code', 'ORDER_EMPLOYEE')->sole()->permissions()->detach(Permission::where('code', 'customer_credits.grant')->sole());
        $this->actingAs($c['actor'])->post(route('orders.customer-returns.store', $c['order']), $this->data($c) + ['compensation_method' => 'CREDIT'])->assertForbidden();
        $this->assertDatabaseCount('customer_return_cases', 0);
        $this->assertDatabaseCount('customer_credit_entries', 0);
        $this->allowCredit($c['actor']);
        $returns = app(CustomerReturnService::class);
        $case = $returns->record($c['actor'], $c['order'], $this->data($c));
        $returns->move($c['actor'], $case, $this->movement($c, '80'), $this->proof('actual cash compensation'));
        $this->assertThrows(fn () => app(CustomerCreditService::class)->fromReturn($c['actor'], $case), ValidationException::class);
        $this->assertDatabaseCount('customer_credit_entries', 0);
        $this->assertDatabaseCount('customer_return_movements', 1);
    }

    public function test_grant_permission_does_not_authorize_redemption_or_payout(): void
    {
        $c = $this->sold();
        $role = Role::where('code', 'ORDER_EMPLOYEE')->sole();
        $role->permissions()->detach(Permission::whereIn('code', ['customer_credits.redeem', 'customer_credits.payout'])->pluck('id'));
        $service = app(CustomerCreditService::class);
        $customer = $c['order']->customer;
        $service->grant($c['actor'], $customer, $this->creditData($c));
        $this->assertThrows(fn () => $service->redeemDelivery($c['actor'], $c['order']), AuthorizationException::class);
        $this->assertThrows(fn () => $service->cashPayout($c['actor'], $customer), AuthorizationException::class);
        $this->actingAs($c['actor'])->get(route('customers.show', $customer))->assertOk()
            ->assertSee(__('credit.grant'))->assertDontSee(__('credit.redemption_blocked'))->assertDontSee(__('credit.payout_blocked'));
        $role->permissions()->syncWithoutDetaching(Permission::whereIn('code', ['customer_credits.redeem', 'customer_credits.payout'])->pluck('id'));
        $role->permissions()->detach(Permission::where('code', 'customer_credits.grant')->sole());
        $this->assertThrows(fn () => $service->grant($c['actor'], $customer, $this->creditData($c)), AuthorizationException::class);
        foreach ([fn () => $service->redeemDelivery($c['actor'], $c['order']), fn () => $service->cashPayout($c['actor'], $customer)] as $operation) {
            $this->assertThrows($operation, ValidationException::class);
        }
        $this->assertSame('30.0000', $service->balances($c['actor'], $customer)->sole()->available);
    }

    public function test_unsupported_redemption_and_captain_cash_payout_leave_credit_and_history_intact(): void
    {
        $c = $this->sold();
        $this->allowCredit($c['actor']);
        $customer = $c['order']->customer;
        $s = app(CustomerCreditService::class);
        $s->grant($c['actor'], $customer, $this->creditData($c, '150'));
        $before = DB::table('financial_entries')->orderBy('id')->get()->toJson();
        $this->assertThrows(fn () => $s->redeemDelivery($c['actor'], $c['order']), ValidationException::class);
        $this->assertThrows(fn () => $s->cashPayout($c['actor'], $customer), ValidationException::class);
        $this->assertSame('150.0000', $s->balances($c['actor'], $customer)->sole()->available);
        $this->assertSame($before, DB::table('financial_entries')->orderBy('id')->get()->toJson());
        $entry = (array) DB::table('customer_credit_entries')->sole();
        foreach (['amount', 'kind', 'scope', 'update', 'delete'] as $defect) {
            try {
                DB::transaction(function () use ($entry, $defect): void {
                    if ($defect === 'update') {
                        DB::table('customer_credit_entries')->update(['amount' => '0']);

                        return;
                    }
                    if ($defect === 'delete') {
                        DB::table('customer_credit_entries')->delete();

                        return;
                    }
                    unset($entry['id']);
                    $entry['request_key'] = (string) str()->uuid();
                    $entry['reference'] = (string) str()->uuid();
                    if ($defect === 'amount') {
                        $entry['amount'] = '-1';
                    }
                    if ($defect === 'kind') {
                        $entry['kind'] = 'DELIVERY_REDEMPTION';
                    }
                    if ($defect === 'scope') {
                        $entry['recorded_by'] = User::factory()->create()->id;
                    }
                    DB::table('customer_credit_entries')->insert($entry);
                });
                $this->fail('SQL bypassed credit history protection');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
        $this->assertDatabaseCount('customer_credit_entries', 1);
    }

    #[DataProvider('collectionContracts')]
    public function test_existing_collection_contract_rejects_reduced_cash_without_consuming_credit(int $count, InvoiceCollectionMethod $method): void
    {
        $c = $this->sold($count, collect: false);
        $service = app(CustomerCreditService::class);
        $service->grant($c['actor'], $c['order']->customer, $this->creditData($c));
        $invoice = $c['invoice'];
        $amount = (string) BigDecimal::of($invoice->grand_total)->minus('5');
        try {
            app(InvoiceCollectionService::class)->record($c['actor'], $invoice, $method, $amount, $c['currency']->id, now());
            $this->fail('Reduced cash cannot satisfy the existing full-invoice collection contract.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('domain.collection_must_equal_the_full_invoice_total_in_its_currency')], $exception->errors()['amount']);
        }
        try {
            DB::transaction(fn () => $invoice->collection()->create([
                'order_id' => $c['order']->id, 'currency_id' => $c['currency']->id, 'amount' => $amount,
                'collection_method' => $method, 'collected_at' => now(), 'recorded_by' => $c['actor']->id,
                'captain_id' => $method === InvoiceCollectionMethod::Cash ? $c['assignments'][0]->captain_id : null,
                'order_captain_id' => $method === InvoiceCollectionMethod::Cash ? $c['assignments'][0]->id : null,
            ]));
            $this->fail('SQL accepted reduced cash as full payment.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
            $this->assertStringContainsString('Collection must match the full current issued Invoice', $exception->getMessage());
        }
        $this->assertSame($count === 1 ? 3 : 2, $c['order']->fresh()->accounting_contract_version);
        $this->assertSame($invoice->grand_total, $invoice->fresh()->grand_total);
        $this->assertSame('30.0000', $service->balances($c['actor'], $c['order']->customer)->sole()->available);
        $this->assertDatabaseCount('invoice_collections', 0);
        $this->assertDatabaseCount('financial_entries', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public static function collectionContracts(): array
    {
        return [[1, InvoiceCollectionMethod::Cash], [1, InvoiceCollectionMethod::ShamCash],
            [2, InvoiceCollectionMethod::Cash], [2, InvoiceCollectionMethod::ShamCash]];
    }

    public function test_captain_personal_cash_cannot_be_disguised_as_an_approved_expense(): void
    {
        $c = $this->sold();
        $before = DB::table('financial_entries')->orderBy('id')->get()->toJson();
        $accounts = app(FinancialAccountService::class);
        $account = $accounts->getOrCreate($accounts->ownerForCaptain($c['captains'][0]), $c['currency'], FinancialAccountKind::CaptainCompanyReceivable);
        try {
            app(FinancialPostingService::class)->postEvent($c['currency'], 'CUSTOMER_CREDIT_CASH_PAYOUT', 999999,
                'CAPTAIN_EXPENSE_REIMBURSEMENT_DUE', 1, now(),
                [['account' => $account, 'direction' => FinancialEntryDirection::Increase, 'amount' => '30']],
                $c['branch'], $c['actor']);
            $this->fail('Customer cash payout is not an approved operational expense.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
            $this->assertStringContainsString('Missing benefit review source', $exception->getMessage());
        }
        $this->assertSame($before, DB::table('financial_entries')->orderBy('id')->get()->toJson());
    }
}
