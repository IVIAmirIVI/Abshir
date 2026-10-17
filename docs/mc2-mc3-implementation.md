# MC2 participant foundation and MC3 read-only financial reconciliation

Baseline: `livewire-ux`, `02a56be`, 2026-10-09. Development checkpoint only. All existing MC1/reset work is retained. No development/preview migration, seeding, Orders, cleanup, commit or push was performed. Tests use verified `abshir_testing` and disposable generated schemas only.

## Delivered code

`OrderCaptainSelectionService::select()` accepts one or exactly two distinct eligible Captains, an explicit collector among them, actor, global UUID and change reason. Existing assignment/removal permissions and Order Branch scope apply. A same-key exact retry returns the original source; changed actor, Order, participants, collector or reason conflicts. The global request-key advisory transaction lock prevents two different Orders from racing through the unique index into a raw SQL error.

Order locks serialize selection, source recording and competing changes. Captain eligibility is protected by sorted `FOR NO KEY UPDATE` locks, compatible with unrelated financial-owner FK reads. A changed set/collector closes existing participation and creates new rows, retaining both Captains when the collector switches. A purchase, any Invoice or external bill request binds attribution and prevents later selection. DELIVERED, CANCELLED, cancellation-intent and issued-Invoice guards remain intact.

`order_captain_selections` is an immutable source with Order, Branch, sorted assignment IDs, collecting assignment, `SINGLE_V1`/`SHARED_COMPANY_V1`, actor, time, UUID and reason. Deferred PostgreSQL checks ensure the latest source agrees exactly with active assignments: one/two participants, one primary, matching Branch, collector and assignment time. Audited assignment identity and closed history cannot be rewritten/deleted. Existing unversioned legacy multiple-Captain history is not converted or capped retroactively. New contract limits are not a claim that every old API has acquired a global two-Captain limit.

Wizard Step 3 optionally selects participants and collector; Review and creation share the same authoritative draft. Creation and audit occur in the existing transaction and submission-key retry boundary. An unchanged Branch preserves the draft; a genuine change invalidates dependent Captain selection. Existing delivery overrides reuse the explicit collector, audited request/approval mechanism and exact fee. Pending exceptions retain the automatic fee. No new permission, role or dependency was introduced.

The existing delivery allocator supplies two full delivery units, each eligible entitlement and one combined discount. A collector switch now also checks assignment IDs when deciding whether repricing is necessary; unchanged Captain IDs/count no longer leave allocations pointing at removed participation. Old pricing versions are preserved.

Workflow provides the optional audited selector, controlled financial-boundary messages and recent selection history. The normal screen has no split or settlement forms. New financial review is a branch/permission-authorized GET page and rejects Captain administrative/mixed-role access unless the existing Super Admin bypass applies. Shared payer display uses actual immutable purchase allocations, not a guessed collector. New history uses Gregorian Western-digit dates in Asia/Damascus. Data loading batches allocation relationships and bounds selection history to 25 sources.

## Exact read-only MC3 calculations

`SharedOrderFinancialPlanService::review()` reuses `InvoiceSnapshotService::build()` and current delivery snapshots under the Order/Currency locks. It requires explicit shared selection, all actual item prices and all physical per-Shop MC1 confirmations. Each Shop's actual line total and Currency must agree with its recorded principal; both actual payer assignments and collector must match selection. Missing/mismatching sources fail closed.

Products are counted once. Delivery is the existing applied unit fee times two. Combined discount is taken once. Each Captain's calculated company obligation is their own recorded per-Shop funding plus their eligible existing delivery entitlement. Company-funded shortfall is already contained in the full entitlement; no duplicate shortfall credit is added. Negative company delivery net remains visible under an approved discount. Exact BigDecimal arithmetic and existing Currency/HalfUp policy are reused; no float, principal rounding, precision change or SYP bill-fee change.

Example tested: products 80, delivery unit 10, configured Captain share 50%, no discount. Customer total 100; physical funding 40 and eligible entitlement 5 for each Captain; company obligations 45 each; calculated company net 10. CASH review shows anticipated collector custody 100 **only if actual future collection occurs**; SHAM_CASH review shows zero Captain custody under the existing company-direct method. Deposit effect is zero. A discount of 20 once gives customer total 80, obligations still 90 and company net -10. None of these reviews records a transaction or implies company receipt/payout.

The service returns source IDs, selection/collector/assignment identity, Branch, Currency/precision, pricing ID, item fingerprint and calculated amounts. It is deliberately not a persisted financial snapshot, settlement ledger, Invoice or approval. Repeated review performs zero writes across Accounts, Owners, Transactions, Entries, deposits, payouts, Invoices and Collections. PostgreSQL already freezes Currency precision on verified purchase use; review never rounds historical payer shares.

## Disabled transitions and remaining implementation

1. Shared customer Invoice issuance and Collection require a new versioned authoritative source contract bound to physical purchases. Reusing old aggregate product-funding/CASH Deposit-debit identities would change audited meaning.
2. Shared CASH must create full-gross collector Custody, separately attributed company Receivables for both Captains, and real company handover/payout sources. Existing bill-principal handovers and Captain working-capital Deposit receipts cannot represent full customer handover. No imaginary handover, payout, netting or Captain-to-Captain settlement is implemented.
3. Existing payout source validation, source hash/reversal contracts and partially-paid correction/recovery dispositions need the shared source types before activation. Existing individual financial services remain available for unrelated/legacy sources; calculated plans are never payable entries.
4. Shared Shop returns/refunds require immutable actual-refund allocation to original physical payers. Partial returns, shortfalls, collected corrections and already-paid recovery cannot use the old one-payer return header. MC1 guards remain closed.
5. External bill completion needs a shared source bound to the exact frozen collector while preserving principal custody, company fee, evidence, cancellation and SYP nearest-5 HalfUp. Selected shared bill requests remain unavailable instead of promising an incomplete flow. Historical bill behavior is unchanged.
6. The owner-approved full shared-CASH business policy is **not** awaiting approval. Its source/ledger implementation is unfinished. The unresolved owner policy is the per-Shop odd minor-unit recipient. Proposed option for review: allocate the residual to lower frozen assignment ID per Shop. Current service rejects indivisible halves with zero writes; divisible purchases remain supported in the MC1 backend.

The additional source boundary rejects legacy Invoice/bill/single-payer insertion and parent reassignment into selected shared Orders before any physical purchase exists. PHP guards return controlled messages for old financial APIs. Delivery is blocked before a contradictory final state can be recorded. Pre-purchase cancellation remains legal and retains participant history. Source protection has not been weakened to expose shared execution.

## Dashboard and reset

Read-only plans do not appear as dashboard earnings, paid Receivables, company handovers or balance changes. Each Captain's participation counts the Order once despite collector reassignment history. Existing authenticated User-to-Captain self identity, cross-Branch balance labeling, separate accounts and currency boundaries remain unchanged.

Reset maintenance recognizes `order_captain_selections`; FK/deferred checks and original guard restoration remain active. No new ledger source mapping was invented. This batch only reviews dependency closure and tests refusal/allowlist behavior. Destructive reset with the new schema still needs dedicated isolated acceptance and must not be inferred from the earlier preview run.

## Migrations and activation

- Existing owner MC1: `2026_10_09_000000_add_shared_shop_purchase_foundation.php` (unchanged).
- New MC2: `2026_10_09_010000_add_order_captain_selections.php`.
- New boundary: `2026_10_09_020000_guard_selected_shared_order_financial_paths.php`.

All are forward-only, ordered after the existing Shop/route/tiered-fee schemas. They do not backfill/conflate historical assignments or alter completed money snapshots. New migrations are verified only in isolated testing schemas. No activation on `abshir` is authorized or performed. Do not deploy them as full shared-accounting availability: complete and audit the remaining shared source/posting/refund contracts first.

## Test infrastructure and acceptance

`tests/Support/run_isolated_pgsql.php` verifies actual `current_database() = abshir_testing`, creates a random test schema, runs the existing PostgreSQL PHPUnit configuration with process-only 512M memory, then removes only its generated configuration/schema. This avoids altering an already-applied owner migration to work around surviving PostgreSQL functions. Concurrency workers use independent PHP/Laravel processes and production services; direct SQL is synchronization/observation/fixture setup, not a replacement business algorithm. Blocking-graph assertions prove real contention, including one global key across different Orders.

The wider regression found legacy fixture timestamp precision/hash mismatch and historical upgrade fixtures missing modern Shop-origin/route prerequisites. Fixture corrections reread persisted Collection facts and supply those independent pricing prerequisites while retaining the deliberately old financial guard stages. No production financial formula/validator is changed to make fixtures pass.

Current acceptance results and complete Git inventory are recorded in the overnight owner report. No full suite or browser UAT is implied. See `uat-backlog.md` for the consolidated manual acceptance and deferred live-accounting work.

## Final automated evidence — 2026-10-09

The final wider, explicitly selected PostgreSQL run completed on PHP 8.5.10: **308 passed, 0 failed, 3100 assertions**, 457.131 seconds. It ran 19 related test files covering the new selection/Wizard/review/integration features, MC1 shared physical purchases, legacy posting/Invoice/Collection/bill/delivery/workflow paths, Captain dashboards, and their independent-process concurrency regressions. This was not the full application suite. All database work stayed within verified `abshir_testing`; the generated schema/configuration was removed afterward.

Final selected files:

- OrderCaptainSelectionTest / OrderCaptainSelectionConcurrencyTest
- SharedOrderFinancialPlanTest / SharedOrderWizardTest / SharedOrderIntegrationSafetyTest
- SharedOrderShopPurchaseTest / SharedOrderShopPurchaseConcurrencyTest
- OrderFinancialPostingTest / OrderFinancialConcurrencyTest
- InvoiceAndCollectionTest / InvoiceConcurrencyTest
- OrderBillPaymentTest / OrderBillPaymentConcurrencyTest
- DeliveryPricingTest / DeliveryPricingOverrideConcurrencyTest
- OrderWorkflowTest / OrderCaptainConcurrencyTest
- CaptainSelfViewTest / CaptainInformationDashboardTest

Quality: PHP syntax passed for 55 changed/untracked non-Blade PHP files. Scoped Pint passed for 54 files; the final subsequently changed legacy concurrency fixture also passed. The excluded pre-existing `scripts/order-cleanup-preview-audit.php` retains its inherited formatting findings and was not altered. Vite production build passed. Final Blade compilation passed for 302 files; Arabic/English structural parity passed for `shared_orders` (34 keys) and `domain` (312 keys). `git diff --check` passed. No dependency/lockfile changes. Build output remains ignored.

Separate review closed direct SQL reassignment/history rewriting and Draft-Invoice reparenting bypasses in the new contracts, with regression coverage. No source mutation was used to weaken legacy financial validators. No runtime diagnostics remain. No browser inspection, full suite, destructive reset acceptance, development migration or real financial operation is implied.

Remaining acceptance boundary: live shared Invoice/Collection/gross-Custody/Receivable/handover/payout/refund source contracts must be implemented and reviewed before shared execution can be activated. The only unresolved owner allocation decision is the per-Shop odd minor-unit recipient. MC2 and read-only MC3 are reviewable development progress, not end-to-end operational readiness or release approval.

## Independent checkpoint audit — 2026-10-09

Checkpoint scope is all currently uncommitted MC1/MC2/read-only MC3/reset work. Baseline remains `livewire-ux` / `02a56be`; no staging, commit, push, merge or owner-database mutation.

Confirmed and fixed findings:

- Purchase request UUIDs are globally unique, but the physical purchase services originally only serialized by Order. Added a common transaction advisory key in shared and legacy purchase paths, after the Order lock and before Shop/Currency locks. A new regression failed before the fix because neither worker waited on the key; final independent-process races prove real contention and a controlled conflict across different Orders, including competing shared/legacy paths.
- Canonicalize selection and purchase UUIDs to lowercase before advisory locks. PostgreSQL UUID identity is case-insensitive; differently cased keys must share the same lock. Tests now use uppercase/lowercase competing requests.
- Shared purchase evidence service now requires `order_shop_purchases.view`, matching the existing download endpoint. Order visibility alone is insufficient; direct service and HTTP regressions deny it.
- Retain the historical local cleanup JSON but exclude it explicitly from Git. It contains financial/source/evidence references and large schema fingerprints. Also ignore generated `.phpunit-mc-*.xml` test configurations. The historical cleanup README identifies its evidence as local-only and its outcome as historical.
- Scoped Pint corrected inherited whitespace-only findings in the preview preflight script; its read-only behavior is unchanged.

Automated verification: the wider selected financial/authorization/integration run passed **231 tests / 2282 assertions**. After the final UUID/security changes, the related shared-purchase/selection-concurrency/integration run passed **34 tests / 279 assertions**. These runs overlap and must not be summed as unique coverage. No full suite or browser UAT. PHP syntax passed for all 55 eligible non-Blade PHP files; scoped Pint passed, with final changed files checked again. Blade compilation passed (302), Arabic/English parity passed (34 shared_orders keys; 312 domain keys), Vite build passed (existing dependency CSS typehint warning only), and diff whitespace checks passed.

Migration order remains `000000` -> `010000` -> `020000`, forward-only. Tests exercise actual PostgreSQL FKs, active triggers, deferred completeness, immutable sources, rollback, legacy upgrades, collector identity and financial barriers. A separate direct PDO read-only connection verified actual `abshir`, read-only on, **none of these migrations registered**, and **none of the new schema present**. No development activation was performed.

Reset coverage includes both new dependency tables: `order_shop_purchase_allocations` and `order_captain_selections`. Actual PostgreSQL FK traversal and active DELETE-guard structure tests passed; CLI refusal under testing/unattended execution remains effective. The existing destructive harness targets the owner preview database/public schema, while the production reset engine deliberately refuses testing/generated schemas. It therefore does not support an authorized disposable deletion test here. No identity spoof, guard weakening, owner reset or preview test execution was used. Real destructive compatibility on the new schema remains a deferred acceptance requirement before future reset use after activation, not a passed claim.

No open blocker for a development-only checkpoint remains in the checked implementation. Shared Invoice/Collection/gross-Custody/Receivable/handover/payout/refunds and shared bill execution stay disabled. Odd minor-unit payer policy, full regression and consolidated browser UAT remain deferred. This is not release/merge or financial activation approval.

Recommended subject: `Add audited shared captain foundation and safe demo order reset`.
