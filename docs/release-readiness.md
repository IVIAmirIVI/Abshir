# Abshir release readiness — final launch sprint

Development preparation only. Baseline: `livewire-ux` / `1941330`. Existing RET/CREDIT and invoice-display work is preserved. No owner database activation, production deployment, configuration switch or financial write is authorized by this document.

## Supported contracts and explicit boundaries

- Ordinary and mixed CASH/SHAM_CASH collections retain original immutable invoices, actual-payment amounts, credit consumption and V1/V2/V3 accounting. Upper-tier SYP external bill-payment fee remains 3%, nearest 5 SYP HALF_UP.
- A new customer-return decision is **independent compensation**, not reversal of its historical collection. This includes eligible delivered mixed-settled orders. Product quantity, product/fee amount and cumulative invoice compensation caps still apply. A consumed grant is never silently restored. New compensation decisions are fulfilled once in full; historical pre-activation cases retain their original contract.
- Partial use of multi-responsibility return credit requires an explicit per-responsibility amount. Immutable allocation records inherit actor, occurrence time, order, branch and currency from their settlement. Full use may consume the exact recorded remaining responsibilities; it does not choose a proportional/FIFO split. Only actually consumed credit funds the specifically attributed Captain debt.
- Full available Customer credit can be paid from an identified Captain's **personal physical cash**, with actual-handover attestation, private evidence and reference. Credit is debited atomically and exactly one `CUSTOMER_CREDIT_PERSONAL_FUNDS_DUE` receivable source is posted. The existing source-attributed payout lifecycle reimburses that Captain separately. No operating-funds/custody debit, automatic offset, bonus, expense or presumed company bank posting is created.
- Original mixed-payment refund/reversal and post-collection cancellation remain blocked: restored credit versus actual refunded money, financial reversal and previously recovered Captain responsibility require a separately approved contract. Company/custody-funded customer-credit payouts remain unsupported. External API integration remains deferred. Shop commission payment records remain a standalone subledger, not proof of company bank/cash GL movement.

## Activation order — requires separate owner approval

The three already-applied RET/CREDIT migrations must not be edited or replayed on UAT. New forward-only migrations, in order:

1. `2026_10_16_000000_enable_independent_mixed_return_compensation.php`
2. `2026_10_16_010000_add_explicit_credit_responsibility_attributions.php`
3. `2026_10_16_020000_add_personal_funds_customer_credit_payouts.php`

Each migration runs transactionally on PostgreSQL. Existing guards are retained except the specific RET creation boundary replaced by independent-compensation semantics. Exact prerequisite function bodies are checked before patching; unexpected versions abort. Historical case/redemption version markers preserve pre-activation facts; no money is backfilled. Plan a maintenance window: financial/source tables are locked during activation. Stop writers first and stop on any failure. No automatic down/rollback is supported.

For approved UAT activation only: verify `local`, exact `abshir_uat_v2` identity, public schema, expected Git/release artifact and prerequisite migrations; create and restore-verify a fresh full backup plus private-file backup; then execute each migration using `php artisan migrate --path=database/migrations/<exact filename> --force`. Never run a reset, refresh, unrestricted migration, seeder or migration retry against another database. Confirm migration records, enabled triggers, valid FKs, original invoice/collection/credit/ledger fingerprints, Captain balances and ordinary collection guards afterwards.

After that separate approval and identity verification, the exact UAT commands are:

```text
php artisan migrate --database=pgsql --path=database/migrations/2026_10_16_000000_enable_independent_mixed_return_compensation.php --force
php artisan migrate --database=pgsql --path=database/migrations/2026_10_16_010000_add_explicit_credit_responsibility_attributions.php --force
php artisan migrate --database=pgsql --path=database/migrations/2026_10_16_020000_add_personal_funds_customer_credit_payouts.php --force
```

These commands were not executed on UAT. Post-activation history comparisons must use the pre-existing columns: the new contract/attribution version metadata is expected to read 1 for old rows. No original money, source, actor or timestamp may change.

No new permission is introduced in this sprint. Required existing permissions: `customer_credits.view`, `.grant`, `.redeem`, `.payout`, with the existing Customer/Order/Invoice and Branch permissions. Personal-cash payout uses `.payout` plus Customer credit/view access. Company reimbursement still requires `captain_receivable_payouts.create`; recording a Customer handover grants no reimbursement authority. Review AuthorizationSeeder role mappings before any separately approved seeding. Do not remove existing assignments or grant Captain write permissions.

## Production environment and fresh initialization

Hosting is not chosen. Do not assume a provider, domain, proxy, database administrator or successful TLS configuration.

- Provision a **new independent** PostgreSQL 18 database and restricted application role. Do not clone business/UAT records or restore a UAT dump as production. Retain original UAT/history databases separately. Validate locale/provider, database/schema identity and privileges first. Apply all reviewed migrations in filename order in the fresh target only; never use `composer setup`, demo reset, `migrate:fresh` or demo seeders.
- Review `.env.production.example` and `release-preparation-runbook.md`; provide secrets outside Git. Required: `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL`, unique protected APP_KEY, `DB_CONNECTION=pgsql`, exact production database/schema, correct network/TLS credentials. Use TLS `verify-full` where the approved DB topology supports it. UTC persistence and existing Asia/Damascus business-date presentation remain unchanged.
- Decide `ORDER_ACCOUNTING_V3_SINGLE_CAPTAIN` explicitly with the owner. Leave it disabled until the installed V3 schema and single/shared routing have passed deployment checks. Do not rely on a temporary shell variable or different web/CLI environments. Compare redacted effective web/worker/CLI configuration after cache rebuild and process restarts; no historical contract version changes.
- Use database/durable sessions and cache, `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax` unless a separately justified deployment flow requires another value. Restrict trusted proxies to actual infrastructure. Keep `CAPTAIN_SESSION_API_ENABLED=false`. Do not configure real mail/payment/messaging integrations without separate approval.
- Run only reviewed `AuthorizationSeeder` on the fresh production database after authorization; no example business data. Enter approved Branches, Areas, Currencies, Captain rules, pricing routes, active SYP policy and partnership agreements through reviewed master-data administration/import. Do not activate commission drafts implicitly. Verify zero initial Orders, Invoices, Collections, financial entries/transactions and application-derived Captain balances.
- `DatabaseSeeder` creates an admin only in `local`; it is **not** a production admin bootstrap. Create the first production admin via a separately reviewed, interactive one-off bootstrap on the verified fresh DB. Collect the password using a hidden terminal prompt inside a closure, hash it using Laravel Hash, create one active verified user and attach only the existing `SUPER_ADMIN` role in one transaction. Fail if that identity exists. Never place the password in command arguments, shell history, logs or this document. Verify login, then close/remove bootstrap access. No production admin was created by this sprint.

## Build, serving, private files and process readiness

- Use the reviewed release artifact and committed lockfiles: `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`, `npm ci`, `npm run build`. Confirm PHP 8.5 extensions and PostgreSQL driver. Do not deploy source build caches from a developer machine. Build config/routes/views caches only with the target environment installed; never regenerate an established APP_KEY.
- Serve only `public/`; deny `.env`, `.git`, source, vendor, tests, docs, backups and directory listing. HTTPS is mandatory. Restrict database/admin ports and host access; run the application under a minimally privileged service identity. Do not start with public registration or default/UAT passwords.
- Give the runtime limited write access to `storage` and `bootstrap/cache`. All evidence disks, including `customer_refunds` used for Customer handover evidence, must remain outside the public document root and public symlinks. Retain original evidence bytes/hashes, private authorized download routes and durable file backups. Verify that unauthenticated, foreign-Branch and wrong-record requests cannot download files. Do not migrate UAT evidence as business history.
- Supervise workers if using `QUEUE_CONNECTION=database`; confirm failures/retries and restart workers on release. Run Laravel's scheduler once per minute. `routes/console.php` schedules `abshir:deliver-benefit-notifications` with overlap protection. Customer credit/return notices currently write to the internal database synchronously in the source transaction; do not assume external delivery. Verify actual worker/scheduler liveness separately from configuration checks.
- Run the existing read-only `php artisan abshir:release-preflight --database=<approved target> --production --json`. It is evidence of configured prerequisites, not proof of TLS, restore success, financial reconciliation or launch authorization.

## Backup, restore validation and recovery

Before activation and launch, stop/suspend writers with owner approval. Use PostgreSQL 18 `pg_dump` custom-format full backups with unique timestamped paths outside Git/web roots; keep credentials in protected connection configuration, not arguments/output. Record database identity, tool/server versions, exit code, bytes, SHA-256 and `pg_restore --list` result. Back up private evidence and protected APP_KEY/config separately with restricted ACLs and documented retention.

Verify a real restore into a **new uniquely named verification database** after confirming it does not exist. Match locale/provider and confirm `current_database()` before writes. Use `pg_restore --no-owner --no-privileges --single-transaction --exit-on-error`; never `--clean`, `--create` or `--disable-triggers`. Compare snapshot-consistent table/migration counts, valid FKs, enabled financial triggers/functions, important indexes, representative source fingerprints, exact financial entries, application-derived Captain balances and evidence hashes. Leave verification databases intact for owner review. A successful archive listing alone is insufficient.

On incident: suspend writers and preserve logs, new financial events, evidence and backups; do not delete/rewrite them or issue compensating settlements without policy. Revert application code only if the earlier artifact is compatible with the forward schema. Forward-only financial migrations must not be rolled back. If recovery needs restoration, restore into a fresh recovery DB, reconcile post-backup facts, verify balances/files and obtain approval before changing the connection. Never restore over the source DB. Rebuild caches and restart web/worker processes after any approved switch. Record recovery point/time and all omitted post-backup activity explicitly.

After new attribution or personal-cash sources are used, a pre-sprint application artifact is not an acceptable functional rollback: it lacks the explicit liability display and/or the canonical new reimbursement source. Keep a compatible reviewed artifact available and prefer a forward fix. Restoring an older backup would also omit later handovers, repayments or collections; those facts require explicit reconciliation before any approved recovery switch.

## Final launch gates and smoke sequence

1. Owner reviews uncommitted source, migration SQL, permissions and test evidence; creates a reviewed checkpoint separately.
2. Owner approves backup/restore verification and path-specific activation on disposable UAT. No autonomous activation occurred.
3. Perform only the outstanding focused browser checks first: invoice 9 (300 = CASH 270 + credit 30), invoice 10 (250 = SHAM_CASH 200 + credit 50), ordinary invoice, desktop/mobile. Browser tools are unavailable in the current sprint; visual acceptance remains pending.
4. After approved activation, verify new independent compensation, explicit partial attribution over more than one order, full personal-cash handover, private evidence, management notice and exact source reimbursement. Include invalid/duplicate/stale and cross-Branch attempts. No unsupported refund/custody path may become actionable.
5. On the fresh production target, verify roles/branches/configuration and zero balances without creating business transactions. Check login, authorized navigation, unauthorized direct IDs, HTTPS/cookies, private downloads, assets, logs, scheduler/worker and verified restore. Any write-based smoke records require separate owner approval and a documented plan; do not contaminate the launch baseline silently.
6. Launch only after financial regression, focused UAT, infrastructure and recovery gates pass and the owner approves deployment. No claim of production readiness is made here.

## Outstanding evidence

Targeted and final isolated regression results are recorded in the sprint report. No owner database migration/seed/write, `.env` edit, deployment or Git mutation is permitted during this sprint. Actual first-launch infrastructure, protected admin bootstrap, fresh-database configuration and backup/restore rehearsal still require the owner/operator. These are deployment gates, not reasons to invent another financial feature.

## Implementation map and focused evidence

Changed by this sprint, in addition to the three new migrations listed above:

- A: `app/Services/CustomerReturnService.php`, `resources/views/orders/_customer-returns.blade.php`, `lang/ar/returns.php`, `lang/en/returns.php`.
- B: `app/Services/MixedCustomerCreditService.php`, `app/Livewire/Orders/Workflow.php`, `resources/views/livewire/orders/workflow-invoice.blade.php`, `lang/ar/credit.php`, `lang/en/credit.php`.
- C: new `app/Services/CustomerCreditCashPayoutService.php`; updated `app/Http/Controllers/CustomerCreditController.php`, `app/Services/CustomerCreditService.php`, `app/Enums/FinancialEventType.php`, `app/Services/FinancialPostingService.php`, `app/Services/FinancialBalanceQueryService.php`, `app/Services/CaptainReceivablePayoutService.php`, `routes/web.php`, `resources/views/customers/_credit.blade.php`, `resources/views/components/financial/receivable-label.blade.php`, `lang/ar/financial.php`, `lang/en/financial.php`. Credit translations above also cover personal-cash handover.
- Tests: new `tests/Feature/LaunchCustomerCompensationTest.php`, `tests/Feature/CustomerCreditCashPayoutTest.php`; updated `tests/Feature/MixedCustomerCreditTest.php`, `tests/Feature/CustomerReturnCaseTest.php`, `tests/Feature/CustomerReturnConcurrencyTest.php`, `tests/Feature/CustomerReturnLegacyCompatibilityTest.php`, `tests/Support/customer_return_worker.php`.
- Documentation: this file and `docs/customer-credit-01.md`. Earlier RET/CREDIT, invoice breakdown changes, UAT evidence and the three historical untracked documents are preserved.

Focused PostgreSQL evidence (all through `tests/Support/run_isolated_pgsql.php`, verified `abshir_testing`, disposable schemas):

- Mixed CASH/SHAM_CASH independent full compensation: 4 passed / 50 assertions, including unchanged historical invoice, payment, credit consumption and ledger fingerprints.
- The initial wider selection had 96 tests: 94 passed, 2 errors caused by old installment-based test setup. Both were corrected to use the approved full-payment contract. The correction/SQL-guard/history selection then passed 7 tests / 50 assertions.
- Explicit attribution including direct SQL rejection of missing allocation, incorrect sum and excess party responsibility: 1 passed / 20 assertions.
- Personal-cash handover and separate partial CASH plus final TRANSFER company reimbursement, retry/overpayment protection, private evidence and the shared source label: latest HTTP integration/authorization selection passed 2 tests / 37 assertions. It verifies authorized form visibility, real controller submission, source identity, private evidence and rejection of a foreign-Branch POST. The wider initial handover group passed 8 tests / 62 assertions; four additional malformed-source SQL cases passed in the correction selection above.
- A deterministic two-process test reproduced SQLSTATE `40P01` before the final lock-order fix: a return-credit grant held Currency before Customer while personal-cash payout held Customer before Currency. `CustomerReturnService::record()` now takes the Customer lock before SQL Currency guards for CREDIT compensation, matching the other credit writers. No financial formula, arbitrary delay or application retry was added. The test then passed, and the complete concurrency group passed 12 tests / 98 assertions.
- Existing pre-sprint version-1 installment history was created on its actual historical schema, then all three forward migrations were applied in isolation. Original movements and financial entries stayed identical, and the remaining historical installment completed under its original rules. New decisions cannot select that old contract.
- A separate pre-activation multi-party CREDIT redemption retained attribution version 1, exact original financial entries, funded Captain amount and its original idempotent retry after activation: 1 passed / 9 assertions. No artificial attribution records were backfilled.

Frontend tests: 16 passed, 0 failed/skipped. Relevant PHP syntax, scoped Pint, Blade compilation, translation-key parity (credit 50 / returns 62 / financial 270 keys per language), Vite build and `git diff --check` passed. Build retains a non-blocking existing utility type-hint warning. No new package or dependency upgrade was added.

These focused runs overlap and must not be added together as a unique test total. The final concurrency fix, source-label/HTTP assertions, historical compatibility and malformed SQL-source checks were additionally verified after the full run started; no second full run is planned.

## Final regression gate and corrections

The single complete isolated run used verified `abshir_testing`, schema `mc_testing_50be63d6f8662bcb`: **1,328 tests, 1,323 passed, 4 assertion failures, 0 execution errors, 1 skipped, 30,348 assertions**, 2,418,663 ms. The disposable schema was cleaned by the runner. Raw diagnostic log is outside Git at the local temporary path `abshir-final-launch-full.log`; it contains rendered fixture HTML and is not a release artifact.

The four failures had two confirmed causes, both corrected:

- `AdminErrorLocalizationTest::test_admin_pages_translate_system_roles_permissions_and_preserve_custom_data` and `LocalizationFoundationTest::test_canonical_roles_and_custom_permissions_do_not_mutate_codes`: the pre-existing customer-credit permissions lacked the `customer_credits` group and `grant`, `redeem`, `payout` action labels. Added matching Arabic/English labels in `lang/ar/admin.php` and `lang/en/admin.php`. No authorization codes, grants or permission checks changed.
- `CaptainFinancialOperationsTest::test_old_branch_financial_history_remains_visible_after_transfer_without_leaking_other_branch` and `CaptainManagementNavigationTest::test_historical_branch_links_survive_transfer_without_advertising_forbidden_profile_access`: the return-liability panel incorrectly required current operational access to a transferred Captain, rejecting otherwise authorized historical financial pages. `CustomerReturnService::captainLiabilities()` now relies on its two existing permission gates and its recorded-Branch-scoped query. Profile and all mutation guards remain unchanged.

Those exact four tests plus a new concrete return-liability transfer/isolation regression passed together: **5 tests, 585 assertions, 0 failures/errors/skips**, schema `mc_testing_d0973ef2205e4dc9`. The new test verifies the original Branch still sees the actual funded obligation, the new Branch cannot see that history, and the old Branch still cannot open the transferred Captain's operational profile. The final source has targeted verification after these corrections; **a completely green full-suite rerun was not performed or claimed**.

The installed-public-schema invoice guard test remains intentionally skipped when the historical migration prerequisite is absent from the testing database's `public` schema. The isolated migration chain and historical-schema tests exercise compatibility without installing anything in an owner database.

Final correction checks passed: PHP syntax, scoped Pint, Blade compilation, translation parity (`admin` 254, `credit` 50, `returns` 62, `financial` 270 keys per language), and `git diff --check`. The earlier 16 frontend tests and Vite build passed; these final corrections change no frontend assets or dependencies. Browser tools were unavailable: real desktop/mobile invoice #9/#10 visual inspection and the new financial paths remain pending owner-approved UAT activation and browser verification.

Development verdict: ready for owner review of the implementation and three forward migrations. This is **not** UAT activation approval or production readiness. There is no known remaining failure from the completed regression run, but final full-suite-green evidence and real-browser evidence must not be inferred from targeted tests.
