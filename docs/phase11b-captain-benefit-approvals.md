# Phase 11B: Captain benefit approval, accrual and attributed payout

Extends Phase 11A without changing Shop commissions, Order accounting V1/V2,
delivery fees, discounts, the 3% SYP external bill fee policy or existing ledgers.
No owner database has been activated by this task.

## Approved lifecycle and authority

Proposed drafts can be revised using the Phase 11A append-only version chain.
Submitting freezes that revision for review. Separate permissions
`captain_bonuses.approve` and `captain_expenses.approve` control approval,
rejection and documented reversal. They are initially granted to SUPER_ADMIN
only; employees and Captains receive no default grants. Existing permission
administration supports explicit future delegation. Read access and Branch
scope are still independently required. Existing `captains.update` controls
submission/cancellation and original draft administration.

Creator, latest revision author and submitter may never approve their own
request, including SUPER_ADMIN. Both service and PostgreSQL enforce this.
Rejection needs a reason. Unapproved requests can be cancelled without posting.
Rejected/cancelled/submitted revisions are immutable; further proposals require
a separate draft. Approval does not mean payment. There are no automatic bonuses,
automatic expense approvals or metric-based formulas.

Expense approval requires intact private evidence, validated hash/size/actual
JPEG, PNG or WebP MIME. Bonus evidence is optional; the original documented
description remains required. No product-purchase financing, SHAM_CASH
reimbursement, Deposit, Custody or delivery entitlement is recategorized as an
operational expense. An optional Order is informational, branch-matched and
references an actual Captain assignment; delivery/collection timing is not used.

## Immutable source contract

Approval and posting are one transaction. An immutable APPROVE review is the
source: `CAPTAIN_BENEFIT_APPROVAL`, its review ID, source version 1, and exactly
one `CAPTAIN_BONUS_DUE` or `CAPTAIN_EXPENSE_REIMBURSEMENT_DUE` event. Exactly one
entry increases the correct Captain's `CAPTAIN_COMPANY_RECEIVABLE`, for precisely
the approved amount, Currency and original Branch. Event time and actor match
the review. Deposit and Cash Custody remain untouched.

PostgreSQL enforces canonical event/source identities in both directions,
exact entries, terminal-review uniqueness, immutable reviews, and deferred
source-to-posting completeness. Arbitrary source types cannot bypass a known
benefit event. Approval, payout and reversal use Currency locking consistently;
request UUIDs, locked revisions, payload hashes and existing ledger identities
prevent retries from creating duplicate obligations. The new additive migration
extends only the existing payout validator's precise event whitelist and installs
independent benefit guards; historical migration files and V1/V2 guards are unchanged.

## Real payout through the existing screen

Approved entries appear in the existing Captain financial source allocation
screen. Partial/full payouts use the original `CaptainReceivablePayoutService`,
private evidence, exact source allocations, Branch/Currency remaining limits,
locks and idempotency. No duplicate payout screen is introduced. New benefit
allocations require actual `CASH`/`TRANSFER` and Captain receiver User identity;
TRANSFER additionally requires an actual reference. The database enforces these
facts. Historical payouts retain NULL method/receiver; no historical data is rewritten.

Third-party receivers are intentionally unsupported without an approved delegation
contract. Actual payout reduces Receivable; this does **not** claim a company
cash/bank ledger entry. Shared V2 Order receivables remain non-allocatable, while
independent approved benefits for the same Captain can be allocated normally.

Only entirely unpaid benefits may be reversed. An immutable REVERSE review with
a mandatory reason is the authoritative `CAPTAIN_BENEFIT_REVERSAL` source. The
existing posting service records an exact linked reversal. Any paid portion
blocks automatic reversal in both service and database; no negative payout,
automatic cash recovery, offsets or currency conversion is introduced.

## Presentation, activation and boundaries

Captain administration shows proposed, pending, rejected, cancelled, approved
unpaid, partially paid, fully paid and reversed states distinctly. The existing
Captain self-view remains read-only and its account balances include these
valid postings; no Captain write permissions or new Portal are introduced.

Both uncommitted Phase 11 migration files require separate owner approval before
activation. Never execute them on owner databases as part of automated testing.
Existing proposals/financial tables stay intact, with restrictive FKs preserving
review/source history. Demo reset compatibility is not expanded or tested here.

Browser UAT is deferred. Three older unidentified full-suite failures remain
outside scope and are not claimed resolved. Remaining closure steps are owner
review, migration activation approval, explicit role delegation if desired,
then the consolidated acceptance session. Eligibility limits, expense categories,
semantic duplicate-claim policy, recovery after payment, third-party receivers
and future company cash/bank integration need explicit future policy if expanded.

## Focused verification result

Across the initial focused selection and retries of failed cases, all **43
distinct selected tests** have a final passing result: **0 failures, 0 errors,
0 skips, 1016 assertions** (sum of each case's latest result, not one full-suite run).
The two real-service concurrency tests passed with **25 assertions**:
competing approvals, excessive competing payouts, reversal versus payout, and
a new independent draft versus an existing obligation payout. Independent PHP
workers use independent PostgreSQL connections; the parent verifies actual lock
contention before releasing the Currency row. Helpers only bootstrap, create
fixtures, synchronize and transport results; production services decide business
outcomes.

Verification found and corrected a SQL CASE comparison syntax issue, then
timestamp precision mismatches. The new migration uses microsecond review times
and widens request timestamp precision to six digits without shifting existing
instants. A fractional-second regression validates source/event instant equality.
A test-only constraint-mode issue was corrected: intermediate integrity checks
restore the normal deferred mode before performing a complete payout, then check
all constraints immediately after completion. Production guards were preserved.

PHP syntax, scoped Pint, Blade compilation, relevant Arabic/English key parity,
financial enum-label coverage and git diff checks passed. The initial migration
compile error caused subsequent migration-fixture errors inside that temporary
test schema; retries used fresh isolated schemas. No owner database, .env,
historic migration, or financial policy was modified. No comprehensive suite,
browser UAT, demo reset or Git staging/commit/push was performed.

### Uncommitted checkpoint inventory (11A + 11B)

The three pre-existing untracked Phase 9/UAT documents remain preserved and
excluded from this feature inventory.

     M app/Enums/FinancialEventType.php
     M app/Http/Controllers/CaptainFinancialController.php
     M app/Models/CaptainReceivablePayout.php
     M app/Services/CaptainReceivablePayoutService.php
     M app/Services/FinancialBalanceQueryService.php
     M app/Services/FinancialPostingService.php
     M database/seeders/AuthorizationSeeder.php
     M lang/ar/admin.php
     M lang/ar/financial.php
     M lang/en/admin.php
     M lang/en/financial.php
     M resources/views/captain-financials/show.blade.php
     M resources/views/captains/show.blade.php
     M routes/web.php
     M tests/Feature/OrderAccountingV2Test.php
    ?? app/Http/Controllers/CaptainBenefitRequestController.php
    ?? app/Models/CaptainBenefitRequest.php
    ?? app/Models/CaptainBenefitReview.php
    ?? app/Services/CaptainBenefitRequestService.php
    ?? app/Services/CaptainBenefitReviewService.php
    ?? database/migrations/2026_10_12_174355_create_captain_benefit_requests_table.php
    ?? database/migrations/2026_10_12_182244_enable_captain_benefit_approvals_and_payouts.php
    ?? docs/phase11a-captain-requests.md
    ?? docs/phase11b-captain-benefit-approvals.md
    ?? lang/ar/captain_requests.php
    ?? lang/en/captain_requests.php
    ?? resources/views/captain-requests/_form.blade.php
    ?? resources/views/captain-requests/_review.blade.php
    ?? resources/views/captain-requests/index.blade.php
    ?? resources/views/captain-requests/show.blade.php
    ?? tests/Feature/CaptainBenefitApprovalTest.php
    ?? tests/Feature/CaptainBenefitConcurrencyTest.php
    ?? tests/Feature/CaptainBenefitRequestTest.php
    ?? tests/Support/CaptainBenefitFixtures.php
    ?? tests/Support/captain_benefit_worker.php

