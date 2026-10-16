# Customer credit: implementation and accounting boundary

## Final launch sprint update — 2026-10-16 (pending separate activation)

The CREDIT-01/CREDIT-02 implementation history below is retained for context. Its descriptions of blocked independent mixed-return compensation, partial multi-party attribution and personal-cash payout are superseded by these new forward-only contracts. They remain unavailable on an owner database until the corresponding migrations are separately approved and applied. See `release-readiness.md` for exact filenames, activation order and deployment gates.

- **Independent compensation:** a new return decision against a mixed-settled invoice may create one full, documented compensation entitlement. It does not refund or reverse the original collection. New cash/transfer compensation is paid once in full; historical version-1 installment records remain immutable and retain their existing rules. Original mixed refunds/reversals and cancellation remain blocked.
- **Explicit attribution:** each new return-credit redemption records immutable amounts against the original responsible parties. A partial multi-party redemption requires explicit operator allocation, exactly matching the consumed amount and within each party's remaining cap. Full remaining redemption uses the exact recorded remaining shares; a single responsible party is unambiguous. No proportional or FIFO allocation is invented. Legacy redemption facts retain attribution version 1.
- **Personal-cash payout:** `CustomerCreditCashPayoutService::record()` requires the entire available Customer/Branch/Currency balance, an identified Captain, actual personal-cash handover attestation, private evidence, reference, reason and actual time. It consumes all remaining grants atomically and creates exactly one canonical `CUSTOMER_CREDIT_CASH_PAYOUT` / `CUSTOMER_CREDIT_PERSONAL_FUNDS_DUE` source with one Captain company-receivable increase. The existing source-attributed payout service reimburses it separately, with actual method, Captain receiver and evidence. Company/custody funding and reversal of an actual handover remain blocked. The old argument-free `cashPayout()` stub is not a recording API.
- **Funded liability:** unused grants create no funded Captain debt. Explicit redemption or actual personal-cash handover funds only the recorded Captain-attributed portion. Recoveries cannot exceed funded outstanding debt. Operating Deposit and Cash Custody remain separate and unchanged by these new handover/reimbursement actions.
- **Safety:** all implementation tests run only through the isolated PostgreSQL harness in `abshir_testing`. No owner database, `.env`, existing applied migration, financial policy percentage or historical financial record is changed. Browser acceptance of the new paths remains pending after approved activation.

## Approved owner policy

- Credit is an existing company obligation, separate for each Customer, Branch and Currency.
- Delivery redemption is noncash tender. Delivery 100, credit 30 and actual cash 70 must reconcile without reducing the Captain's original entitlement or recording credit as newly collected cash.
- A Captain's entitlement shortfall is borne by the company, with a separately attributable receivable and no automatic offset.
- An identified Captain may pay the entire available balance from personal funds only with actual Customer handover evidence. The company then owes that Captain an independently attributable receivable. Neither operating funds nor custody is implicitly debited.
- Company/custody funding requires a proven source and supported postings; collection money is not assumed to be personal money.
- ORDER_EMPLOYEE and SUPER_ADMIN have independent credit view, grant, redemption and payout permissions. No prior approval is required; management receives informational notices.
- A return records responsibility immediately. Unused credit does not fund Captain debt. Only actually redeemed or paid-out value attributable to that Captain can fund it. Mixed-party partial attribution must not be guessed.

## Working grant foundation

`CustomerCreditService` grants immutable, exact-decimal credit for documented overcharges/errors and atomically for a CREDIT return case. The grant records its Customer, Branch, Currency, source, actor, time, reference and idempotency identity. Management is notified in the same transaction. Customer and Order screens show scoped balances/history; authorized employees can grant credit from the Customer page.

The return compensation method is fixed when recording the case. CREDIT cannot also be compensated through a cash/bank return movement, nor follow historical invoice correction/refund paths. Grants alone do not change Captain Deposit, Company Receivable or Cash Custody, or make recorded responsibility payable.

Permissions are `customer_credits.view`, `.grant`, `.redeem`, `.payout`. Grant does not imply redemption/payout. Redemption additionally requires invoice collection/view and scoped Customer/Order access. No seeder or migration was run against an owner database.

## CREDIT-02 mixed settlement

### Delivery redemption

The forward-only migration `2026_10_15_230914_add_mixed_customer_credit_settlements.php` adds immutable `customer_credit_settlements` and `customer_credit_redemptions`. Each settlement uniquely references its issued invoice and explicitly allocates positive amounts to original credit grants in the same Customer/Branch/Currency.

The existing Workflow collection form accepts the amount actually received and per-grant credit allocations. Its preview uses exact decimal arithmetic. The server checks `actual_amount + credit_amount == invoice.grand_total`, `credit_amount <= invoice.delivery_total`, currency precision, grant availability/time and authorization. Products and unrelated services cannot consume credit. Original invoices, prices and entitlement snapshots remain unchanged.

`invoice_collections.amount` is **only the actual payment**. A separate settlement records noncash credit. Ordinary collections retain the original full-invoice validation function and strictly-positive guard. Only an explicit, database-validated mixed source can satisfy the invoice using cash plus credit. Deferred constraints require exact collection metadata and the complete credit allocation set in one transaction. The migration checks the exact installed financial function definitions before extending them; unexpected prerequisites abort installation.

### Financial effects

Let D be the original delivery amount after any existing discount, R the redeemed credit and E the original frozen Captain entitlement. Credit is not another discount. Product principal remains counted once.

- **V2 CASH:** original full Captain delivery receivables remain unchanged. The designated collector's delivery cash custody is only `D - R`; credit is never cash custody. The company settles its prior Customer obligation through credit consumption while retaining its full Captain liability.
- **V2 SHAM_CASH:** record only the actual electronic payment; no Captain cash custody. Original product funding and delivery entitlement receivables remain unchanged.
- **V3 CASH:** the Captain retains actual product cash and `D - R` delivery cash. Reuse the existing company-share operating debit `max(D - R - E, 0)` and separate shortfall receivable `max(E - (D - R), 0)`. No automatic offset of that receivable against operating funds or custody. Original E and invoice snapshots do not change.
- **V3 SHAM_CASH:** record only the actual electronic payment. Existing product reimbursement and full delivery entitlement receivables remain unchanged; no implied Captain cash.
- Shop commission eligibility recognizes validated mixed settlement; product commission amounts/timing and historical snapshots are unchanged. Reports aggregating collection amounts continue to aggregate actual payment, not credit.

Example: delivery 100, entitlement 50, credit 30 gives delivery cash 70. V3 company-share debit is 20, Captain shortfall is 0. With credit 80, delivery cash is 20 and a separately attributable Captain shortfall of 30 is due. In V2 the full entitlement of 50 remains due in both cases. If products cost 80, the corresponding total actual collections are 150 and 100, respectively; the 80 product principal is not mistaken for delivery cash.

### Atomicity, cancellation and reversal

The lock order is Order, Customer, Invoice, then existing financial locks. The Customer lock serializes grant consumption across different Orders; PostgreSQL also locks/rechecks it in the redemption guard. An immutable request key/hash returns the same successful collection on exact retry and rejects different facts. Notification, source debit, collection and financial posting roll back together on failure.

There is no early reservation: merely viewing/selecting credit does not consume it, and cancellation before collection needs no credit release. Once collected, existing invoice/ledger immutability and V2/V3 correction/reversal restrictions remain enforced. New credit sources are also immutable. No automatic recredit of a successfully settled invoice is allowed.

**Post-collection mixed refunds/cancellation remain blocked.** A supported future path must explicitly split restored credit versus actual money refunded, preserve original consumption/financial events, and reverse funded Captain responsibility consistently with any repayments. It must not reuse a full-cash historical refund or silently reset an invoice. RET creation against a mixed-settled invoice is explicitly unavailable until that source-attributed refund path exists.

## Captain cash payout stays disabled (outside CREDIT-02)

`CaptainBenefitReviewService` creates receivables only for actual approved BONUS/EXPENSE requests, with a separate reviewer. PostgreSQL `guard_benefit_event_identity()` requires that exact review source. Customer-credit payout is neither a bonus nor an operational expense approval. `FinancialBalanceQueryService::payableReceivableEntries()` and the payout allocation guard accept specific attributable source events, not a generic arbitrary receivable.

The missing implementation is a private-evidenced Customer handover source with an atomic credit debit and its canonical personal-funds Captain receivable, plus source-attributed payout eligibility. It cannot safely reuse an unrelated order/bonus/expense event. No cash handover, credit debit or Captain receivable is registered until those pieces are supported together. Company/custody-funded alternatives remain blocked as well.

## Return responsibility attribution

Granting CREDIT compensation still funds no Captain debt. Actual redemption of the case's original grant now funds that responsibility by the redeemed amount, using collection time. Summary, Captain liability queries and recovery caps include only actual settled redemptions and existing actual compensation, less actual repayments.

For a single responsible Captain, granting 80 yields debt 0; redeeming 10 yields debt 10; actual repayment 4 leaves 6. Partial redemption of a grant backed by several responsible parties is blocked rather than choosing an allocation. Full redemption of that grant funds the exact original responsibilities. No FIFO, proportional splitting or automatic offset is assumed.

## Validation and activation status

Focused tests exercise V2/V3 CASH and SHAM_CASH mixed collections, unchanged snapshots, source-attributed shortfall payout, multi-grant/multi-Order credit use, Workflow submission/retry, original grant permissions, Branch/Currency isolation, return-funded debt, rollback, cancellation/reversal boundaries, SQL complete-set enforcement and concurrent consumption. Existing accounting regression selections verify ordinary V2/V3 contracts and V1 history. Tests run through the isolated PostgreSQL runner only; no broad browser UAT or full regression suite.

Mixed delivery settlement is submitted for owner review before any UAT activation. Captain personal-cash Customer payout is deliberately disabled. Owner databases and `.env` remain unchanged. Test writes run exclusively through `tests/Support/run_isolated_pgsql.php` in disposable schemas inside `abshir_testing`. The RET, grant-ledger and mixed-settlement migrations must be reviewed in that order; none has been applied to an owner database by this task.

CREDIT-02 validation: the representative selection ran 99 tests (861 assertions), with 98 passing and one numeric-format assertion mismatch (`0` versus `0.0000`). That assertion now checks exact numeric zero. Its rerun, the final Workflow checks and final concurrency checks all pass. Combining the latest result of each distinct selected case gives **99 passed, 865 assertions, zero failures/errors/skips**. This is a focused selection, not a full-suite run. PHP syntax, scoped Pint, Blade compilation, Arabic/English key parity and the Vite build passed. The build retains the existing nonblocking Tailwind typehint warning. No real-browser acceptance is claimed.

## CREDIT-02 file inventory

New files:

- `app/Services/MixedCustomerCreditService.php`
- `database/migrations/2026_10_15_230914_add_mixed_customer_credit_settlements.php`
- `tests/Feature/MixedCustomerCreditTest.php`

Updated production files (including previously untracked RET/CREDIT work):

- `app/Livewire/Orders/Workflow.php`
- `app/Services/InvoiceCollectionService.php`
- `app/Services/OrderAccountingV2Service.php`
- `app/Services/OrderAccountingV3Service.php`
- `app/Services/ShopCommissionEligibilityService.php`
- `app/Services/CustomerCreditService.php`
- `app/Services/CustomerReturnService.php`
- `resources/views/livewire/orders/workflow-invoice.blade.php`
- `resources/views/orders/_invoices.blade.php`
- `resources/views/orders/_customer-returns.blade.php`
- `resources/views/customers/_credit.blade.php`
- `lang/ar/credit.php`
- `lang/en/credit.php`

Updated supporting files:

- `tests/Feature/CustomerCreditTest.php`
- `tests/Feature/CustomerReturnCommissionTest.php`
- `tests/Feature/CustomerReturnConcurrencyTest.php`
- `tests/Support/SharedPurchaseFixtures.php`
- `tests/Support/CustomerReturnFixtures.php`
- `tests/Support/customer_return_worker.php`
- `docs/customer-credit-01.md`

All other pre-existing RET-01/CUSTOMER-CREDIT-01 changes, historical documents and UAT evidence are preserved. Branch remains `livewire-ux`, committed HEAD `1941330`; no staging or Git mutation was performed.
