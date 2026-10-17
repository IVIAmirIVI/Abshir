# Phase 10B — partner Shop commission agreements

Development implementation on `livewire-ux`, baseline `d6d4726`. Phase 10A and all its append-only drafts remain preserved. This document records the subsequent owner approvals; the Phase 10A report describes its earlier scope.

## Approved direction and implemented scope

Shops owe commissions to Abshir. The existing partnership screen now configures inactive agreement revisions: PERCENTAGE or FIXED, exact nonnegative value, Shop/Product/Product Group scope, explicit Currency, Group priority, effective interval and notes. Percentages must be at most 100. Fixed proposals must fit Currency precision; invalid precision is rejected, never rounded. These validations do not define the missing financial calculation policy. No new permission or dependency is introduced.

New configured drafts require active catalog references and Currency. A Product/Group is master catalog configuration; a ShopProduct listing is not fabricated or required to prepare an agreement. Shop identity comes exclusively from the authorized nested partnership. Area-less Shops remain Super Admin only. Existing partnership read/create/update permissions and Currency/catalog permissions apply server-side.

Creation and revisions retain Shop-before-Partnership locking. Revisions also lock the prior draft, reject stale versions and forks, preserve its target/Currency identity, immutable snapshots and author/time history. Notes-only revisions preserve all configured fields. Target/Currency changes require a separate scope, not mutation of history.

## Matching preview, never an earning

`ShopCommissionRuleResolver::preview()` returns MATCHED/NONE/CONFLICT/UNAVAILABLE with at most one draft. It does not calculate an amount. Product wins over Product Group, then Shop. Among applicable Groups, the explicitly entered **higher integer priority wins**. A tie at the highest matching level returns CONFLICT with no selected rule. Draft conflicts may be saved for review; activation cannot proceed. There is no activation or settlement route/button.

Currency must match exactly; no conversion or unspecified-Currency fallback occurs. Effective start is inclusive, end exclusive. Group membership and partnership intervals are checked at the supplied time, not implicitly at today or ordered_at. Suspended partnerships do not match. Ended partnerships retain interval-based historical visibility. A later revision applies only from its effective start; expiry does not revive superseded versions. An optional `knownAt` audit cutoff reproduces what configuration was recorded at that historical instant. Membership/partnership status changes themselves are not reconstructed as commission snapshots.

`previewItem()` derives the persisted item's Order, registered Shop, Product and actual Currency (or estimated Currency only when no actual price exists), enforces Order and Shop Branch access, and resolves the partnership interval. One-time Shops have no partner rule. Items without a master Product can match only Shop scope. Multiple applicable partnerships fail closed. The caller must supply preview time: the final earning snapshot time still needs approval.

The existing partnership UI exposes matching Product/Currency/time controls, configured values, priorities, effective dates and preserved revisions in Arabic/English. Inputs use Damascus local time; stored timestamps and SQL comparisons retain absolute instants and microseconds. A match is explicitly labeled configuration only. No-match never hooks into or blocks normal Order creation.

## Lifecycle eligibility and financial boundary

`ShopCommissionEligibilityService::inspect()` is an authorized read-only lifecycle assessment. The Order must be DELIVERED and its **current ISSUED Invoice** must have an actual Collection with matching Order, Currency, full amount and collected time. Historical/superseded Collections cannot qualify a different current Invoice. A corrected replacement with inherited payment satisfaction is not treated as a new Collection. Refund/correction commission handling awaits approval.

The logic is independent of one/two Captains, CASH/SHAM_CASH and accounting V1/V2. It does not create earnings, receivables, accounts, balance movements or settlements. `assertActivationReady()` always rejects activation pending financial policy, with a specific ambiguity error when matching Groups conflict. SUPER_ADMIN cannot bypass this policy boundary. Submitted status/activation fields are rejected.

## Additive migration and owner database boundary

Phase 10A migration: `2026_10_12_132329_create_shop_commission_drafts_table.php`.

Phase 10B extension: `2026_10_12_140314_extend_shop_commission_drafts_with_agreement_rules.php`.

The second migration adds nullable method/value/priority/effective fields and unrestricted NUMERIC storage. Existing notes-only Product/Group drafts remain unchanged and excluded from configured matching. A configured Shop target has both catalog IDs NULL. Database checks reject non-finite/negative values, percentages over 100, incomplete configuration and invalid intervals/priority. A separate insert guard enforces fixed Currency precision and prevents downgrading a configured revision to legacy unconfigured state. The original append-only/update/delete/truncate and revision-identity guards stay installed. Unique Shop roots complement the existing Product/Group roots. recorded_at precision is expanded to six digits without changing existing instants; old fractional precision cannot be recovered.

An old draft with a Currency can gain configuration through an audited revision. A Currency-less legacy draft remains notes-only; configuring that target requires a new Currency-specific scope. No UPDATE backfill, financial SQL replacement, financial FK/index change or new ledger event occurs. The extension is forward-only; down() refuses destructive rollback.

Neither migration is applied to an owner database. Missing schema has a controlled unavailable state; no request auto-migrates. Any future installation requires separate owner approval, verified backup/identity and ordered path-specific migration execution.

## Decisions required for Phase 10C

1. What exact percentage base applies before/after discounts, and which discount components count?
2. Is a FIXED value per unit, line or Order? How is a Shop/Group fixed value allocated without duplicate charges?
3. At which exact instant are agreement version, Group membership and inputs frozen for earning, beyond the approved delivered-and-collected eligibility?
4. Which commission Currency/conversion sources and final rounding policy apply? The unrelated 3% SYP external bill policy is unchanged and is not a commission rule.
5. How do returns, partial returns, customer refunds and corrected/replaced Invoices affect commission obligations?
6. Approve the canonical earning/payment/adjustment source identities, event/version/idempotency and immutable accounting contract.

After these decisions, Phase 10C can define immutable per-Shop earning snapshots referencing agreement revision, item, Invoice and Collection plus the approved calculation basis, and a **separate Shop commission receivable concept**. Outstanding will derive from earned amounts minus valid evidenced CASH/transfer payments and approved adjustments, including partial/full settlements. These are architecture requirements, not implemented accounts or recorded money. No customer Invoice increase or automatic Captain debit is permitted. No settlement is inferred from customer collection.

## Verification

All PostgreSQL verification uses `tests/Support/run_isolated_pgsql.php`, which verifies `abshir_testing` and creates disposable schemas. Focused coverage includes existing Phase 10A history/authorization, new HTTP configuration and previews, exact validation, precedence/ties, effective/audit boundaries, Damascus instants, a populated Phase 10A-to-10B migration, real persisted item ownership and real single/shared CASH/SHAM_CASH delivery/collection fixtures. Full rows across Invoice, pricing, source snapshots and ledger tables remain unchanged by commission operations. The existing four-scenario historical V1 upgrade regression is included. No owner database, forbidden preview database, full suite or browser UAT is accessed.

Activation, monetary calculation, earnings and settlement remain deliberately unavailable. This is a configuration/matching development checkpoint, not Phase 10 completion.

### Accepted focused results

| Coverage | Passed | Assertions |
| --- | ---: | ---: |
| ShopCommissionAgreementTest, final run | 27 | 207 |
| ShopCommissionDraftTest, preserved regression | 17 | 115 |
| Existing four-scenario V1 upgrade/history regression | 1 | 61 |
| Unique accepted coverage across focused runs | 45 | 383 |

All accepted runs have zero failures, errors or skips. PHP syntax, scoped Pint, Blade compilation, frontend build and git diff --check passed. Commission translations have 47 matching leaf keys in Arabic/English. The existing vendor Tailwind type-hint warning is non-fatal. `.env` and the three protected Phase 9 documents retain their original SHA-256 fingerprints. There were no dependency, owner database, staged-file, commit or push changes; build/test outputs remain ignored.

## Subsequent Phase 10C owner approval and implementation

The owner subsequently approved percentage of final discounted product line value, fixed per-unit value multiplied by quantity, freezing on Invoice issue, earning after delivery plus full collection, exact Product Currency without conversion, and HALF_UP once per line to Currency precision. These approvals supersede the earlier pending-policy boundary above; the Phase 10A/10B record remains historical. No customer delivery discount is subtracted again from product lines. The current Invoice contract already stores each final product line separately and has no global product discount allocation; an unexplained subtotal/line mismatch fails closed instead of guessing an allocation. The unrelated SYP external bill upper tier remains 3% with nearest-5 rounding.

`2026_10_12_144251_create_shop_commission_subledger.php` adds append-only activation events, issuance witnesses, complete Invoice/line commission snapshots, Shop/Branch/Currency accounts, and EARNING/REVERSAL/PAYMENT records. PostgreSQL enforces rule selection/precedence, configuration/value/quantity/source identities, exact HALF_UP calculation, completeness, earning eligibility, precision, uniqueness and payment balance limits. Account rows serialize competing payments; issuance and activation share ordered Shop advisory locks. Currency locks precede account locks on settlements. Existing financial functions/triggers are not replaced.

Activation/deactivation requires existing partnership update and Currency read permissions plus Shop scope; a reason and actor/time are recorded. Overlapping Group rules in one partnership/Currency require distinct priorities, including future overlapping intervals. New revisions remain inactive until explicitly activated; a previous active revision stays visible and effective until a later activation/deactivation event replaces it. Historical snapshots never follow subsequent revisions.

Invoice issuance records a precise, immutable PostgreSQL issuance witness. PHP freezes from that witness in the same issuance transaction. Deferred constraints require complete snapshots before commit, including explicit zero/no-rule lines. This preserves the existing Invoice/Collection timestamp precision and V1/V2 contracts while avoiding a subsecond rule-selection race. Pre-existing issued Invoice IDs are marked as legacy and never recalculated; pre-existing unissued drafts can still freeze when subsequently issued. No historical financial row is rewritten.

The existing Invoice issuance, collection, delivery and supported correction services invoke commission accounting inside their current transactions. One line can earn only once. Single/shared Captains do not multiply product commissions; multiple Shops get separate accounts, with amounts separated by Branch and Currency. No rule means zero commission and does not obstruct ordinary Orders.

The general ledger currently defines Captain account types, not Shop receivables or company cash/bank assets. The owner-authorized independent Shop commission subledger therefore records obligations and actual acknowledged CASH/TRANSFER receipts without claiming a company asset posting. Reading accounts requires `shops.view` and `company_financials.view`; recording payment additionally requires `shop_partnerships.update`. Both Shop and account Branch access are checked server-side. Positive exact amounts, request identity, nonfuture time, no overpayment and concurrent retries are enforced. There are no new permission grants, Captain debits or customer Invoice surcharges.

Supported V1 corrections reverse the exact original earning via a completed correction reference, preserve all original earnings/receipts, and freeze corrected lines using the original immutable rule. Replacement earning uses existing inherited full-payment satisfaction, never a fabricated second Collection. Shop credit is shown separately without an automatic cash refund. Collected V2/shared corrections and other previously blocked payout/refund paths remain blocked. Receipt correction/refund UI and general-ledger cash/bank integration are not implemented. The existing demo-reset command has not been extended to these new immutable dependencies and must not be used as a Phase 10 cleanup mechanism without a separate compatibility review.

Accepted focused coverage across runs: **118 passed / 1126 assertions**, comprising InvoiceAndCollectionTest 46/486, four V2 scenarios 4/98, commission accounting 20/192, agreement regression 27/207, real-service concurrency 3/20, supported V1 correction 1/8, and draft regression 17/115. Independent PHP/Laravel workers prove actual PostgreSQL row-lock contention for delivery-versus-collection, duplicate receipts and competing payments. Earlier failed development checks exposed a new SQL CASE parsing error and two package API mismatches, all corrected; stale display expectations were aligned to compact rendering while precise stored financial assertions were retained. Only failed or previously unexecuted targeted checks were rerun after corrections.

Owner review and separate approved migration activation are still required before local browser acceptance/Phase 10 closure. No owner database migration, full application suite, browser UAT, staging, commit, push or Phase 11 work occurs in this implementation.
