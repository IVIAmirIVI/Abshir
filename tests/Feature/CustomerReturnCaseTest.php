<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CustomerReturnNotice;
use App\Services\CustomerReturnService;
use App\Services\InternalNoticeService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerReturnFixtures;
use Tests\TestCase;

class CustomerReturnCaseTest extends TestCase
{
    use CustomerReturnFixtures, RefreshDatabase;

    #[DataProvider('versions')]
    public function test_full_invoice_refund_is_explicit_company_cash_and_preserves_v2_v3_history(int $count): void
    {
        $c = $this->sold($count);
        $before = $this->history();
        $data = $this->data($c);
        $data['lines'][] = ['kind' => 'DELIVERY', 'quantity' => '0', 'amount' => (string) $c['invoice']->delivery_total];
        $data['responsibilities'][0]['amount'] = (string) $c['invoice']->grand_total;
        $case = app(CustomerReturnService::class)->record($c['actor'], $c['order'], $data);
        $this->assertSame('FULL', $case->product_scope);
        $this->assertSame('FULL', $case->invoice_refund_scope);
        $this->assertSame(0, $case->movements()->count());
        $payment = app(CustomerReturnService::class)->move($c['actor'], $case, $this->movement($c, $case->amount), $this->proof('full'));

        $this->assertSame($c['invoice']->grand_total, $payment->amount);
        $this->assertSame('0.0000', app(CustomerReturnService::class)->summary($c['actor'], $c['order'])->sole()->unpaid_compensation);
        $this->assertSame($before, $this->history());
        $this->assertSame($count === 1 ? 3 : 2, (int) $c['order']->fresh()->accounting_contract_version);
        $this->assertCount(2, app(InternalNoticeService::class)->visible($c['admin'])->where('type', CustomerReturnNotice::class)->get());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function versions(): array
    {
        return [[1], [2]];
    }

    public function test_two_partial_returns_pay_independently_and_cannot_exceed_product_quantity_or_money(): void
    {
        $c = $this->sold();
        $service = app(CustomerReturnService::class);
        $first = $service->record($c['actor'], $c['order'], $this->data($c, '0.5', '40'));
        $second = $service->record($c['actor'], $c['order'], $this->data($c, '0.5', '40') + ['compensation_method' => 'TRANSFER']);
        $this->assertThrows(fn () => $service->move($c['actor'], $first, $this->movement($c, '15'), $this->proof('installment rejected')), ValidationException::class);
        $service->move($c['actor'], $first, $this->movement($c, '40'), $this->proof('first case paid in full'));
        $secondPayment = $this->movement($c, '40');
        $secondPayment['method'] = 'TRANSFER';
        $service->move($c['actor'], $second, $secondPayment, $this->proof('partial3'));

        $this->assertSame('80.0000', DB::table('customer_return_movements')->sum('amount'));
        $this->assertThrows(fn () => $service->record($c['actor'], $c['order'], $this->data($c, '0.1', '8')), ValidationException::class);
        $this->assertThrows(fn () => $service->move($c['actor'], $first, $this->movement($c, '0.01'), $this->proof('too much')), ValidationException::class);
        $this->assertDatabaseCount('customer_return_movements', 2);
        $this->assertCount(2, Storage::disk('customer_refunds')->allFiles());
    }

    public function test_shared_liability_belongs_only_to_identified_captain_and_partial_repayment_is_separate(): void
    {
        $c = $this->sold(2);
        $before = $this->history();
        $data = $this->data($c);
        $data['responsibilities'] = [['party' => 'CAPTAIN', 'order_captain_id' => $c['assignments'][1]->id, 'amount' => '50'], ['party' => 'COMPANY', 'amount' => '30']];
        $service = app(CustomerReturnService::class);
        $case = $service->record($c['actor'], $c['order'], $data);
        $liability = $case->responsibilities()->where('party', 'CAPTAIN')->sole();
        $receipt = $this->movement($c, '20', 'RECOVERY') + ['responsibility_id' => $liability->id];
        $this->assertSame('0', $service->captainLiabilities($c['actor'], $c['captains'][1])->sole()->outstanding);
        $this->assertThrows(fn () => $service->move($c['actor'], $case, $receipt, $this->proof('premature')), ValidationException::class);
        $this->assertThrows(fn () => $service->move($c['actor'], $case, $this->movement($c, '20'), $this->proof('ambiguous partial')), ValidationException::class);
        $service->move($c['actor'], $case, $this->movement($c, '80'), $this->proof('fully funded mixed case'));
        $service->move($c['actor'], $case, $receipt, $this->proof('received from responsible captain'));

        $this->assertCount(0, $service->captainLiabilities($c['actor'], $c['captains'][0]));
        $this->assertSame('30.0000', $service->captainLiabilities($c['actor'], $c['captains'][1])->sole()->outstanding);
        $this->assertSame($before, $this->history());
        $this->assertSame('0.0000', $service->summary($c['actor'], $c['order'])->sole()->unpaid_compensation);
        $this->assertThrows(fn () => $service->move($c['actor'], $case, $this->movement($c, '31', 'RECOVERY') + ['responsibility_id' => $liability->id], $this->proof('overpayment')), ValidationException::class);
    }

    public function test_liability_history_remains_in_its_original_branch_after_captain_transfer(): void
    {
        $c = $this->sold();
        $service = app(CustomerReturnService::class);
        $data = $this->data($c);
        $data['responsibilities'] = [['party' => 'CAPTAIN', 'order_captain_id' => $c['assignments'][0]->id, 'amount' => '80']];
        $case = $service->record($c['actor'], $c['order'], $data);
        $service->move($c['actor'], $case, $this->movement($c, '80'), $this->proof('historical branch'));
        $newBranch = Branch::factory()->create();
        $c['captains'][0]->update(['home_branch_id' => $newBranch->id]);
        $foreignActor = User::factory()->create();
        $foreignActor->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $foreignActor->branches()->attach($newBranch);

        $this->assertFalse($c['actor']->canAccessCaptain($c['captains'][0]));
        $history = $service->captainLiabilities($c['actor'], $c['captains'][0])->sole();
        $this->assertSame($c['branch']->id, $history->branch_id);
        $this->assertSame('80.0000', $history->outstanding);
        $this->assertCount(0, $service->captainLiabilities($foreignActor, $c['captains'][0]));
        $this->actingAs($c['actor'])->get(route('captains.financials.show', $c['captains'][0]))->assertOk()
            ->assertSee('data-return-responsibility="'.$history->id.'"', false);
        $this->actingAs($foreignActor)->get(route('captains.financials.show', $c['captains'][0]))->assertOk()
            ->assertDontSee('data-return-responsibility="'.$history->id.'"', false);
        $this->actingAs($c['actor'])->get(route('captains.show', $c['captains'][0]))->assertForbidden();
    }

    public function test_replay_is_exact_and_duplicate_reference_or_proof_cannot_create_another_payment(): void
    {
        $c = $this->sold();
        $service = app(CustomerReturnService::class);
        $data = $this->data($c);
        $case = $service->record($c['actor'], $c['order'], $data);
        $this->assertSame($case->id, $service->record($c['actor'], $c['order'], $data)->id);
        $payment = $this->movement($c, '80');
        $first = $service->move($c['actor'], $case, $payment, $this->proof('same'));
        $this->assertSame($first->id, $service->move($c['actor'], $case, $payment, $this->proof('same'))->id);
        $other = $payment;
        $other['request_key'] = (string) str()->uuid();
        $this->assertThrows(fn () => $service->move($c['actor'], $case, $other, $this->proof('different')), ValidationException::class);
        $this->assertThrows(fn () => $service->move($c['actor'], $case, $this->movement($c, '20'), $this->proof('same')), ValidationException::class);
        $data['notes'] = 'Different facts';
        $this->assertThrows(fn () => $service->record($c['actor'], $c['order'], $data), ValidationException::class);
        $this->assertDatabaseCount('customer_return_cases', 1);
        $this->assertDatabaseCount('customer_return_movements', 1);
        $this->assertCount(1, Storage::disk('customer_refunds')->allFiles());
    }

    #[DataProvider('versions')]
    public function test_captain_debt_is_funded_by_actual_compensation_only_and_repayments_reduce_it(int $count): void
    {
        $c = $this->sold($count, productAmount: '200');
        $before = $this->history();
        $data = $this->data($c, '1', '200');
        $data['responsibilities'] = [['party' => 'CAPTAIN', 'order_captain_id' => $c['assignments'][$count - 1]->id, 'amount' => '200']];
        $s = app(CustomerReturnService::class);
        $case = $s->record($c['actor'], $c['order'], $data);
        $party = $case->responsibilities()->sole();
        $initial = $s->summary($c['actor'], $c['order'])->sole()->responsibilities->sole();
        $this->assertSame('200.0000', $initial->amount);
        $this->assertSame('0.0000', $initial->funded_amount);
        $this->assertSame('0.0000', $initial->outstanding);
        $this->assertSame('200.0000', $initial->unfunded_amount);
        $this->assertThrows(fn () => $s->move($c['actor'], $case, $this->movement($c, '1', 'RECOVERY') + ['responsibility_id' => $party->id], $this->proof('premature')), ValidationException::class);
        $this->assertThrows(fn () => $s->move($c['actor'], $case, $this->movement($c, '80'), $this->proof('installment rejected')), ValidationException::class);
        $s->move($c['actor'], $case, $this->movement($c, '200'), $this->proof('full actual compensation'));
        $notice = $c['admin']->notifications()->where('type', CustomerReturnNotice::class)->where('data->event', 'COMPENSATION')->sole();
        $noticeBody = json_decode($notice->data['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('200.0000', $noticeBody['amount']);
        $this->assertSame('200.0000', $noticeBody['case_amount']);
        $part = $s->summary($c['actor'], $c['order'])->sole()->responsibilities->sole();
        $this->assertSame('200.0000', $part->funded_amount);
        $this->assertSame('200.0000', $part->outstanding);
        $this->assertSame('0.0000', $part->unfunded_amount);
        $this->assertSame('200.0000', $s->captainLiabilities($c['actor'], $c['captains'][$count - 1])->sole()->funded_amount);
        $this->assertThrows(fn () => $s->move($c['actor'], $case, $this->movement($c, '201', 'RECOVERY') + ['responsibility_id' => $party->id], $this->proof('more than funded')), ValidationException::class);
        $s->move($c['actor'], $case, $this->movement($c, '50', 'RECOVERY') + ['responsibility_id' => $party->id], $this->proof('actual repayment'));
        $final = $s->summary($c['actor'], $c['order'])->sole()->responsibilities->sole();
        $this->assertSame('200.0000', $final->funded_amount);
        $this->assertSame('50.0000', $final->repaid_amount);
        $this->assertSame('150.0000', $final->outstanding);
        $this->assertSame('0.0000', $final->unfunded_amount);
        $this->assertSame('150.0000', $s->captainLiabilities($c['actor'], $c['captains'][$count - 1])->sole()->outstanding);
        if ($count === 2) {
            $this->assertCount(0, $s->captainLiabilities($c['actor'], $c['captains'][0]));
        }
        $this->assertSame($before, $this->history());
        $this->assertCount(2, Storage::disk('customer_refunds')->allFiles());
    }

    public function test_repayment_cannot_be_backdated_before_actual_funding(): void
    {
        $c = $this->sold();
        $data = $this->data($c);
        $data['responsibilities'] = [['party' => 'CAPTAIN', 'order_captain_id' => $c['assignments'][0]->id, 'amount' => '80']];
        $s = app(CustomerReturnService::class);
        $case = $s->record($c['actor'], $c['order'], $data);
        $this->travel(10)->seconds();
        $s->move($c['actor'], $case, $this->movement($c, '80'), $this->proof('later actual payment'));
        $recovery = $this->movement($c, '20', 'RECOVERY') + ['responsibility_id' => $case->responsibilities()->sole()->id];
        $recovery['occurred_at'] = now()->subSeconds(5)->toIso8601String();
        $this->assertThrows(fn () => $s->move($c['actor'], $case, $recovery, $this->proof('premature historical receipt')), ValidationException::class);
        $this->assertDatabaseCount('customer_return_movements', 1);
    }

    #[DataProvider('invalidMoney')]
    public function test_invalid_currency_amount_and_unattested_money_never_write(string $defect): void
    {
        $c = $this->sold();
        $s = app(CustomerReturnService::class);
        $case = $s->record($c['actor'], $c['order'], $this->data($c));
        $data = $this->movement($c, '10');
        match ($defect) {
            'currency' => $data['currency_id'] = $c['currency']->id + 99,
            'confirmation' => $data['actual_confirmed'] = false,
            'offset' => $data['method'] = 'OFFSET',
            default => $data['amount'] = $defect,
        };

        $this->assertThrows(fn () => $s->move($c['actor'], $case, $data, $this->proof('invalid')), ValidationException::class);
        $this->assertDatabaseCount('customer_return_movements', 0);
        $this->assertCount(0, Storage::disk('customer_refunds')->allFiles());
    }

    public static function invalidMoney(): array
    {
        return array_map(fn ($v) => [$v], ['currency', 'confirmation', 'offset', '-1', '0', 'NaN', 'Infinity', '0.001']);
    }

    public function test_cross_branch_role_forgery_and_private_evidence_are_rejected(): void
    {
        $c = $this->sold();
        $s = app(CustomerReturnService::class);
        $case = $s->record($c['actor'], $c['order'], $this->data($c));
        $captainUser = $c['captains'][0]->user;
        $captainUser->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        $foreign = User::factory()->create();
        $foreign->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $foreign->branches()->attach(Branch::factory()->create());
        foreach ([$foreign, $captainUser] as $actor) {
            $this->actingAs($actor)->post(route('orders.customer-returns.store', $c['order']), $this->data($c))->assertForbidden();
        }
        $data = $this->data($c, '0.1', '8');
        $data['responsibilities'] = [['party' => 'CAPTAIN', 'order_captain_id' => 999999, 'amount' => '8']];
        $this->assertThrows(fn () => $s->record($c['actor'], $c['order'], $data), ValidationException::class);
        $m = $s->move($c['actor'], $case, $this->movement($c, '80'), $this->proof('private'));
        $this->actingAs($foreign)->get(route('orders.customer-returns.evidence', [$c['order'], $m]))->assertForbidden();
        $this->actingAs($c['actor'])->get(route('orders.customer-returns.evidence', [$c['order'], $m]))->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('customer_return_cases', 1);
    }

    public function test_notification_failure_rolls_back_case_and_money_and_does_not_leak_private_files(): void
    {
        $c = $this->sold();
        $s = app(CustomerReturnService::class);
        $case = $s->record($c['actor'], $c['order'], $this->data($c));
        DB::unprepared("CREATE FUNCTION ret_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test notification storage failed''; END'; CREATE TRIGGER ret_test_fail BEFORE INSERT ON notifications FOR EACH ROW EXECUTE FUNCTION ret_test_fail()");
        try {
            $this->assertThrows(fn () => $s->move($c['actor'], $case, $this->movement($c, '80'), $this->proof('rollback')), QueryException::class);
        } finally {
            DB::unprepared('DROP TRIGGER ret_test_fail ON notifications; DROP FUNCTION ret_test_fail()');
        }
        $this->assertDatabaseCount('customer_return_movements', 0);
        $this->assertCount(0, Storage::disk('customer_refunds')->allFiles());
    }

    public function test_database_history_guards_block_edits_and_late_obligations(): void
    {
        $c = $this->sold();
        $case = app(CustomerReturnService::class)->record($c['actor'], $c['order'], $this->data($c));
        foreach (['update', 'delete', 'insert'] as $operation) {
            try {
                DB::transaction(fn () => match ($operation) {
                    'update' => DB::table('customer_return_cases')->where('id', $case->id)->update(['notes' => 'tampered']),
                    'delete' => DB::table('customer_return_responsibilities')->where('case_id', $case->id)->delete(),
                    'insert' => DB::table('customer_return_responsibilities')->insert(['case_id' => $case->id, 'party' => 'COMPANY', 'amount' => '1']),
                });
                $this->fail('Immutable history was changed');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_products_only_is_not_a_full_invoice_refund_and_management_receives_source_details(): void
    {
        $c = $this->sold();
        $case = app(CustomerReturnService::class)->record($c['actor'], $c['order'], $this->data($c));
        $this->assertSame('FULL', $case->product_scope);
        $this->assertSame('PARTIAL', $case->invoice_refund_scope);
        $this->assertSame('80.0000', $case->amount);
        $notice = app(InternalNoticeService::class)->visible($c['admin'])->where('type', CustomerReturnNotice::class)->sole();
        $body = json_decode($notice->data['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($c['invoice']->id, $body['invoice_id']);
        $this->assertSame($c['actor']->id, $body['actor_id']);
        $this->assertSame('COMPANY', $body['details']['responsibilities'][0]['party']);
        $this->assertSame($c['invoice']->items()->sole()->id, $body['details']['lines'][0]['invoice_item_id']);
        $this->assertSame(route('orders.workflow', $c['order']), app(InternalNoticeService::class)->destination($c['admin'], $notice));
        $c['admin']->branches()->attach($c['branch']);
        $c['admin']->roles()->sync([Role::where('code', 'ORDER_EMPLOYEE')->sole()->id]);
        $this->assertSame(0, app(InternalNoticeService::class)->visible($c['admin']->fresh())->where('type', CustomerReturnNotice::class)->count());
    }

    public function test_service_fee_can_be_selected_but_external_bill_principal_cannot_be_refunded_here(): void
    {
        $c = $this->sold(withBill: true);
        $before = $this->history();
        $data = $this->data($c);
        $data['lines'] = [['kind' => 'BILL_SERVICE', 'bill_payment_id' => $c['bill']->id, 'quantity' => '0', 'amount' => '5']];
        $data['responsibilities'] = [['party' => 'SHOP', 'order_shop_id' => $c['shop']->id, 'amount' => '5']];
        $s = app(CustomerReturnService::class);
        $forged = $data;
        $forged['lines'][0]['amount'] = $forged['responsibilities'][0]['amount'] = '100';
        $this->assertThrows(fn () => $s->record($c['actor'], $c['order'], $forged), ValidationException::class);
        $case = $s->record($c['actor'], $c['order'], $data);
        $this->assertSame('NONE', $case->product_scope);
        $this->assertSame('NONE', $case->invoice_refund_scope);
        $s->move($c['actor'], $case, $this->movement($c, '5'), $this->proof('service compensation'));
        $party = $case->responsibilities()->sole();
        $s->move($c['actor'], $case, $this->movement($c, '5', 'RECOVERY') + ['responsibility_id' => $party->id], $this->proof('shop recovery'));
        $data['request_key'] = (string) str()->uuid();
        $this->assertThrows(fn () => $s->record($c['actor'], $c['order'], $data), ValidationException::class);
        $this->assertSame($before, $this->history());
        $this->assertSame('100.0000', $c['bill']->fresh()->principal_amount);
    }

    public function test_forms_render_and_malformed_rows_do_not_bypass_validation(): void
    {
        $c = $this->sold(2);
        $data = $this->data($c);
        $data['responsibilities'] = [['party' => 'CAPTAIN', 'order_captain_id' => $c['assignments'][1]->id, 'amount' => '80']];
        $this->actingAs($c['actor'])->post(route('orders.customer-returns.store', $c['order']), $data)->assertRedirect(route('orders.workflow', $c['order']));
        $this->get(route('orders.workflow', $c['order']))->assertOk()->assertSee(__('returns.create'))->assertSee('name="actual_confirmed"', false);
        $this->get(route('orders.show', $c['order']))->assertOk()->assertSee(__('returns.title'))->assertSee(__('returns.details'))->assertDontSee('name="actual_confirmed"', false);
        $this->get(route('captains.information', $c['captains'][1]))->assertOk()->assertSee(__('returns.liabilities'))->assertSee('80');
        $malformed = $data;
        $malformed['lines'] = ['not an array'];
        $this->postJson(route('orders.customer-returns.store', $c['order']), $malformed)->assertUnprocessable()->assertJsonValidationErrors('lines.0');
        $this->assertDatabaseCount('customer_return_cases', 1);
    }

    public function test_recovery_cannot_target_company_and_case_notification_failure_rolls_back(): void
    {
        $c = $this->sold();
        $s = app(CustomerReturnService::class);
        $case = $s->record($c['actor'], $c['order'], $this->data($c, '0.5', '40'));
        $this->assertThrows(fn () => $s->move($c['actor'], $case, $this->movement($c, '10', 'RECOVERY') + ['responsibility_id' => $case->responsibilities()->sole()->id], $this->proof('not a payer')), ValidationException::class);
        DB::unprepared("CREATE FUNCTION ret_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test notification storage failed''; END'; CREATE TRIGGER ret_test_fail BEFORE INSERT ON notifications FOR EACH ROW EXECUTE FUNCTION ret_test_fail()");
        try {
            $this->assertThrows(fn () => $s->record($c['actor'], $c['order'], $this->data($c, '0.5', '40')), QueryException::class);
        } finally {
            DB::unprepared('DROP TRIGGER ret_test_fail ON notifications; DROP FUNCTION ret_test_fail()');
        }
        $this->assertDatabaseCount('customer_return_cases', 1);
        $this->assertDatabaseCount('customer_return_lines', 1);
        $this->assertDatabaseCount('customer_return_responsibilities', 1);
    }

    public function test_existing_v3_database_correction_guard_remains_active_after_compensation(): void
    {
        $c = $this->sold();
        $s = app(CustomerReturnService::class);
        $case = $s->record($c['actor'], $c['order'], $this->data($c));
        $s->move($c['actor'], $case, $this->movement($c, '80'), $this->proof('new path'));
        try {
            DB::transaction(fn () => DB::table('invoice_financial_corrections')->insert(['order_id' => $c['order']->id]));
            $this->fail('An old correction must not bypass actual compensation.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
            $this->assertStringContainsString('V3 collected correction requires', $e->getMessage());
        }
        $this->assertDatabaseCount('invoice_financial_corrections', 0);
    }

    public function test_database_rejects_forged_money_facts_without_relying_on_form_validation(): void
    {
        $c = $this->sold(2);
        $s = app(CustomerReturnService::class);
        $data = $this->data($c, '0.5', '40');
        $case = $s->record($c['actor'], $c['order'], $data);
        $data['request_key'] = (string) str()->uuid();
        $data['responsibilities'] = [['party' => 'CAPTAIN', 'order_captain_id' => $c['assignments'][1]->id, 'amount' => '40']];
        $otherCase = $s->record($c['actor'], $c['order'], $data);
        $movement = $s->move($c['actor'], $case, $this->movement($c, '40'), $this->proof('valid original'));
        $row = (array) DB::table('customer_return_movements')->find($movement->id);
        unset($row['id']);
        // An unpaid case isolates each forged fact from the already-paid duplicate guard.
        $row['case_id'] = $otherCase->id;
        $foreign = User::factory()->create();
        $foreign->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $branch = Branch::factory()->create();
        foreach ([['amount' => '31'], ['amount' => 'NaN'], ['amount' => '0.001'], ['amount' => '0'],
            ['method' => 'TRANSFER'],
            ['actual_confirmed' => false], ['recorded_by' => $foreign->id], ['branch_id' => $branch->id],
            ['kind' => 'RECOVERY', 'responsibility_id' => $case->responsibilities()->sole()->id],
            ['kind' => 'RECOVERY', 'responsibility_id' => $otherCase->responsibilities()->sole()->id],
            ['case_id' => $otherCase->id, 'kind' => 'RECOVERY', 'responsibility_id' => $otherCase->responsibilities()->sole()->id],
            ['occurred_at' => now()->addDay()->toIso8601String()],
        ] as $index => $forgery) {
            $attempt = array_replace($row, $forgery, ['request_key' => (string) str()->uuid(), 'reference' => 'SQL-forgery-'.$index, 'evidence_sha256' => hash('sha256', 'forgery'.$index)]);
            try {
                DB::transaction(fn () => DB::table('customer_return_movements')->insert($attempt));
                $this->fail('Direct SQL bypassed money protections: '.json_encode($forgery));
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
        $this->assertDatabaseCount('customer_return_movements', 1);
        $this->assertSame('0.0000', $s->summary($c['actor'], $c['order'])->firstWhere('id', $case->id)->unpaid_compensation);
        $s->move($c['actor'], $otherCase, $this->movement($c, '40'), $this->proof('valid full payment after rejected forgeries'));
        $this->assertDatabaseCount('customer_return_movements', 2);
        $this->assertSame('40.0000', $s->summary($c['actor'], $c['order'])->firstWhere('id', $otherCase->id)->responsibilities->sole()->funded_amount);
    }

    private function history(): array
    {
        return collect(['invoices', 'invoice_items', 'invoice_collections', 'financial_transactions', 'financial_entries', 'shop_commission_transactions'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
