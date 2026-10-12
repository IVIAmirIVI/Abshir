# Phase 11A: Captain bonus proposals and operational expense claims

This foundation records **unapproved drafts only**. It does not approve claims,
create a payable entitlement, post ledger events, move balances or provide payout
actions in the original Phase 11A foundation. The current Phase 11B extension is
documented in `phase11b-captain-benefit-approvals.md`; it adds separate audited
approval and attributed payout. Shop commissions and accounting V1/V2 are unchanged.

## Management

Open a Captain page and choose **Bonus proposals and expense claims**. Existing
`captains.view` allows branch-scoped viewing; existing `captains.update` controls
draft creation and revision. These permissions do not grant financial approval.
Evidence downloads additionally require `captain_financial_evidence.download`.
No new default role grants or permission seeders are introduced.

Create a proposed bonus or operational expense claim with a positive exact
Currency amount and description. Expense drafts also record a category and
expense date. Categories are descriptive, not eligibility decisions. Optional
Order references must belong to the same Branch and have a recorded assignment
for that Captain. New requests require a current operational Branch relationship;
historical requests remain visible within their recorded Branch after a transfer.

Revisions retain original ownership, creation time, Captain, Branch, Currency,
type and related Order. They append the new amount, description, expense facts,
revision reason, actor and time. Previous versions and evidence remain intact.
There are no update/delete endpoints. PostgreSQL protects append-only history,
version-chain identity, foreign keys, finite positive amounts and Currency precision.
The database uses unrestricted NUMERIC; no rounding policy is introduced.

Each submission uses a unique request UUID. Exact retries reuse the record;
different payloads with that identity are rejected. Advisory transaction locks,
row locks, unique request/version/predecessor constraints and expected-version
checks protect retries and competing revisions. Distinct UUIDs represent distinct
proposals; semantic duplicate/eligibility policy is not invented. No proposal can
cause a duplicate reimbursement because this foundation never reimburses or posts.

## Evidence and activation

The existing private `captain_financial_evidence` disk is reused: outside public
and automatically served local storage, with `serve=false`. Optional JPEG/PNG/WebP
files are validated by actual MIME and limited to 5 MB by the production service.
Downloads recheck nested Captain ownership, Branch and permission and verify
hash, size and MIME. Replacing an attachment preserves the old version's file.
Failed writes remove newly stored files; attachments are never physically deleted
through a request-management action.

The new additive migration requires **separate owner approval** before activation
on an owner database. Until then the requests page shows an activation-required
message. Only disposable schemas in `abshir_testing` are used for verification.
Owner databases and `.env` remain untouched. No demo reset was run or expanded.
An Order referenced by a proposal cannot be deleted while this audit reference
exists (restrictive FK); compatibility with demo cleanup requires separate review.

## Decisions required for Phase 11B

- Who may submit and who may approve/reject, including self-approval restrictions?
- Bonus formulas, limits and any metrics eligible to generate proposals.
- Operational expense eligibility, categories, mandatory evidence and duplicate
  claim detection. Product purchases, Deposit and Cash Custody remain separate.
- Approval/rejection lifecycle and when an approved amount becomes an entitlement.
- Approved posting contract/events and account movements, distinct from existing
  purchase reimbursements, receivable payouts and Shop commissions.
- Payout method/timing, actual settlement evidence, reversals, recovery and
  treatment of cancelled Orders, changed participation and Currency precision.

Browser UAT remains deferred. Three unidentified older full-suite failures remain
outside this task; this implementation does not claim they are resolved.

## Verification and files

Focused execution through `php tests/Support/run_isolated_pgsql.php
tests/Feature/CaptainBenefitRequestTest.php`: **15 passed, 140 assertions**. The
runner verified `current_database() = abshir_testing` before creating its temporary
schema. PHP syntax, scoped Pint, Blade compilation, Arabic/English key parity and
`git diff --check` passed. No full-suite execution, owner database changes or Git
staging/commit/push occurred.

Modified:
- `resources/views/captains/show.blade.php`
- `routes/web.php`

Added:
- `app/Http/Controllers/CaptainBenefitRequestController.php`
- `app/Models/CaptainBenefitRequest.php`
- `app/Services/CaptainBenefitRequestService.php`
- `database/migrations/2026_10_12_174355_create_captain_benefit_requests_table.php`
- `lang/ar/captain_requests.php`
- `lang/en/captain_requests.php`
- `resources/views/captain-requests/_form.blade.php`
- `resources/views/captain-requests/index.blade.php`
- `resources/views/captain-requests/show.blade.php`
- `tests/Feature/CaptainBenefitRequestTest.php`
- `docs/phase11a-captain-requests.md`

The three pre-existing untracked Phase 9/UAT documents remain untouched.
