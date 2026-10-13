# UAT-FIN-02: versioned Order accounting

Development implementation on `livewire-ux`, baseline `7296a77`. This is a reviewable forward change, not owner-database activation, conversion of V1 balances, browser acceptance or production approval.

## Authoritative binding and history

`2026_10_11_185816_add_versioned_order_accounting_contracts.php` adds the non-null `orders.accounting_contract_version` with allowed values 1/2. All Orders present at activation are conservatively grandfathered to V1, including Orders with no collection yet and existing uncollected Invoice versions. Subsequent Order inserts must use V2. PostgreSQL rejects an explicit new V1 insert and every version change; Eloquent does not mass-assign the field. Binding is independent of `ordered_at`, current date, payment method, application configuration and later Invoice replacement.

Only this new version metadata is added to existing Orders. No ledger row, collection, source snapshot, snapshot hash, purchase, invoice item or historical financial amount is rewritten. The original V1 source resolver and shared amount/set validators remain installed under `accounting_v1_*` names and are dispatched for V1 Orders. V1 normal CASH therefore retains its historical Deposit-debit/shortfall contract, and V1 shared CASH retains its full-Invoice Custody/product-reimbursement contract. Old documentation describes those historical contracts; it must not be used to infer V2 postings.

Before activation, the application still recognizes the old schema as V1. Do not use that as a V2 acceptance environment. After activation the database default/insert guard makes new Orders V2; a schema/model value cannot authorize converting an existing Order.

## V2 money and timing

The frozen issued Invoice remains one full customer Invoice. Its collection records the entire `grand_total` and the existing exact designated collector. A ledger's company Custody is a different fact from the customer collection amount.

| Method | Company Custody | Product Receivable | Delivery Receivable | Deposit |
| --- | --- | --- | --- | --- |
| CASH | Actual collected `invoice.delivery_total`, only on the collector | None | Full frozen `captain_entitlement`, for each Captain | No Order movement |
| SHAM_CASH | None | Frozen `product_funding_share` for each actual payer | Full frozen `captain_entitlement`, for each Captain | No Order movement |

Physical shared purchase allocations remain immutable operational sources; CASH does not turn them into company reimbursement obligations. SHAM_CASH preserves actual purchase attribution and the established exact minor-unit remainder policy.

Custody is posted at real CASH collection, even before delivery. Earned obligations retain the existing issued + collected + delivered boundary and the greatest authoritative timestamp. No payout, handover, assumed private settlement, account offset or cross-account netting occurs automatically. A combined discount reduces actual customer delivery/Custody once, while Captain entitlement remains the full contractual amount; no separate shortfall event duplicates that entitlement. Zero concepts produce no transaction or entry.

For the owner examples (products 100, delivery 120 per Captain, 50% Captain share):

| Scenario | Invoice | Collector Custody | Receivable per Captain | Deposit |
| --- | --- | --- | --- | --- |
| Single CASH | 220 | 120 | 60 | 0 |
| Shared CASH | 340 | 240; other Captain 0 | 60 / 60 | 0 / 0 |
| Single SHAM_CASH | 220 | 0 | 160 | 0 |
| Shared SHAM_CASH | 340 | 0 / 0 | 110 / 110 | 0 / 0 |

These are tested examples, not hard-coded prices or shares. Existing delivery pricing, multi-Shop routes, discounts and Invoice arithmetic are reused. External bill-payment contracts are separate and unchanged: the owner-approved active SYP upper tier remains 3% of the whole principal, nearest 5 HALF_UP; historical requests retain their own rule version.

## Persisted events and database protection

V2 events use canonical `INVOICE_COLLECTION` / collection ID / source version **2**:

- `ORDER_V2_CASH_DELIVERY_CUSTODY_RECEIVED`
- `ORDER_V2_PRODUCT_REIMBURSEMENT_DUE`
- `ORDER_V2_DELIVERY_ENTITLEMENT_DUE`

`OrderAccountingV2Service` is dispatched by both existing posting entry points using the locked Order version. It uses existing exact financial posting, account creation, immutable snapshot validation and idempotency. Notes bind to the authoritative frozen source hash; event actors/times follow the collection and delivery history.

PostgreSQL validates canonical source/Order/version, branch, Currency, full collection, collector, account kind, direction, each Captain's exact amount, source hash, actor, timestamp and eligible lifecycle. Deferred collection/status/history/transaction guards require the entire applicable event set, including normal single-Captain Orders. Incorrect, premature, missing, duplicate or wrong-method events fail atomically. Known V1 Invoice events cannot substitute for V2 events. Existing money precision, payload hash, append-only, FK and immutable snapshot guards remain active.

The normal lock order stays Order -> Invoice/Collection -> Currency -> Accounts. Independent-process tests observe both real business services waiting in PostgreSQL's Order-lock graph before release, rather than relying on workers merely starting together.

## Actual payouts and deliberately blocked operations

Existing evidenced **single-Order** Captain payouts can consume V2 Receivable entries through the same permission/branch/limit/idempotency/transaction path; this does not mark a collection as a payout or touch Custody/Deposit. PostgreSQL's payout source whitelist is extended narrowly, with a further guard excluding shared V2 sources. Shared liabilities remain visible in balances and reconciliation without becoming selectable settlement sources or blocking unrelated valid individual payouts.

Collected **V2** Invoice corrections and generic reversals are blocked in PHP and PostgreSQL. The V1 correction engine reverses Deposit and computes V1 shortfall concepts, so reusing it would corrupt V2's delivery Custody and full Receivable. A future approved correction contract must attribute actual customer refunds, any consumed Custody and already paid/unpaid Receivables; no conversion is guessed here. This additional V2 exception limitation must be reviewed by the owner before release. Existing V1 corrections stay on their original contract.

Existing shared after-purchase returns/cancellations, collected-Invoice corrections, participant changes after binding and shared payouts remain blocked. Shared external bill payments and uncollected Invoice versioning retain their already-supported guards. No new settlement screen or cash-handover source is introduced.

## Verification

All migration/test writes used disposable schemas created by `tests/Support/run_isolated_pgsql.php` after verifying `current_database() = abshir_testing`.

- Relevant integrated selection: **67 passed, 0 failed, 0 skipped, 1293 assertions**. Includes four V2 scenarios, SQL-negative money/source cases, discounts/zero Custody, rollback, explicit single payouts, shared exceptions, SYP historical/3% fee policy, localization and migration/concurrency coverage.
- Dedicated real-process concurrency selection: **10 passed, 0 failed, 0 skipped, 97 assertions** (overlaps the integrated selection).
- Final focused negative/reconciliation check: **3 passed, 0 failed, 0 skipped, 17 assertions** (overlaps, and adds the direct-SQL shared-payout guard regression).
- Final version-dispatch/four-scenario/reconciliation check after the V1 assessment guard: **6 passed, 0 failed, 0 skipped, 157 assertions** (overlaps).
- Upgrade coverage first creates and posts all four V1 scenarios using the actual pre-V2 schema/services, then applies only the new migration. It compares complete contents of 12 source/ledger tables and every original Order field, verifies old balances/posting, rejects historical transaction/entry mutation and version changes, and proves a later new Order binds to V2. No trigger is disabled to create a V1 fixture.
- PHP syntax and scoped Pint pass; Blade compilation: 306 files. Financial translation parity and every enum event label are covered by `FinancialLocalizationTest`. No dependency or frontend asset change.

These counts are separate runs, not summed unique tests. No full suite or browser UAT was run. Existing test cases specifically asserting the old normal-CASH/backfill/correction contract must be exercised with genuine V1 migration-state fixtures during the broader acceptance run; they are not evidence for V2 behavior. The new V2 matrix and actual migration upgrade explicitly separate those contracts.

## Future activation and clean UAT prerequisites

1. Owner reviews this diff, grandfathering boundary and blocked collected-V2 correction/settlement limitations. Do not alter existing V1 Orders to make them V2.
2. Verify exact database/schema and installed prerequisite migration chain/functions, stop writers, create a fresh independently verified backup, and obtain separate owner authorization for path-specific activation. Never run unrestricted migration/reset commands on the owner databases as part of this task.
3. Apply only the reviewed new path and verify version/default/trigger/function installation, unchanged historical rows/balances, original FK protection and unchanged active SYP rule. The migration is forward-only and cannot be rolled back by deleting version metadata or financial guards.
4. Prepare a separately authorized clean V2 UAT baseline with approved master/configuration records and existing Captain identities, without V1 Orders/Invoices/Collections/ledger history. Verify application-derived zero balances, not only empty ledger tables. This task does not reset/rebuild `abshir_uat`.
5. Retest all four scenarios, actual configured fees/discounts, collector attribution and evidence/authorization. Confirm collection does not falsely mark a handover or payout. Perform consolidated browser acceptance and broader regression independently before release approval.

No migration was applied to `abshir` or `abshir_uat`; no access to `abshir_cleanup_preview`, `.env` edit, reset, seeding, Git staging/commit/push or owner business-data write was performed.

Read-only before/after checks matched the row count and complete-row digest of every public table in both owner databases (86 tables each), and `.env` SHA-256 remained unchanged. `abshir`: 3 Orders, 8 transactions, 10 entries. `abshir_uat`: 2 Orders, 4 transactions, 6 entries. Neither database has the new accounting-version column; normal local runtime remains `abshir_uat`. The pre-existing untracked `docs/clean-financial-uat-baseline.md` was preserved.

## Future opt-in V3: retained CASH and branch operating balance (not activated)

The forward migration `2026_10_13_190937_add_single_captain_order_accounting_v3.php` adds the V3 binding without changing any existing V1/V2 Order, source snapshot or ledger entry. The database default remains V2. `config/order_accounting.php` defaults `enable_v3_single_captain` to false; after an explicitly approved application configuration change, `OrderService` binds only a validated single-Captain selection to V3 before the initial INSERT. Both participants and collector are revalidated from actual Branch-scoped Captain eligibility, locked and persisted through the existing selection service in the same transaction. Two-Captain selections retain V2; Orders created without a participant selection also retain V2 because future participation is unknown. A Captain chosen only for an audited Wizard delivery override is likewise validated and persisted before binding, without a duplicate assignment. The existing `SINGLE_V1` participation selection label is independent of the immutable accounting version. Form data cannot select a version. Version changes after creation remain prohibited by PostgreSQL.

Single-Captain V3 posts only once the current immutable ISSUED Invoice is fully collected and the Order is DELIVERED, regardless of which occurs first. The exact event time is the latest of collection, issue and authoritative delivery time. Transactions use `INVOICE_COLLECTION`, the actual collection ID, source version 3, frozen snapshot hash, acting collector-recording user, Branch and Currency. The database requires the complete expected event set and exact claimant, direction, amount and account.

- CASH: the frozen positive `company_collectible` decreases `CAPTAIN_DEPOSIT` (the existing operating balance). Products do not create a company debt. There is no delivery Cash Custody or delivery entitlement Receivable for the share already retained by the Captain. An actual positive company-funded `captain_shortfall` remains an unpaid Receivable; zero concepts create no entry.
- SHAM_CASH: frozen product funding and delivery entitlement increase Receivable, as in V2, using distinct V3 events. No operating debit or Cash Custody is inferred. Existing evidenced, source-attributed payout actions remain usable, but no payout is automatic.
- Operating balance is the signed ledger total for Captain + transaction Branch + Currency. It may be negative. Existing branch views and management reports use these entries; existing explicitly global summaries do not replace branch balances. No new balance table or manual balance UPDATE exists.
- Changes to current delivery percentages cannot change a frozen source. Discounts and company-funded shortfalls retain existing allocation formulas. External bill payments, their real principal handovers, SYP 3% nearest-5 fees, bonus/expense approvals and Shop commissions are independent contracts.

### Explicitly blocked boundaries

V3 allows only one active Captain. Both assignment services and PostgreSQL reject shared participation. Existing V1/V2 shared Orders remain executable under their original contracts. Enabling the application flag does not prevent new shared Orders: the Wizard passes its Step 3 selection to `OrderService`, which validates and binds shared creation to V2 from its first INSERT. Unknown participation remains V2 for later assignment compatibility. An already-bound V3 Order never downgrades to V2 when a second Captain is requested; that request is rejected.

Owner decisions for shared CASH: who owes Abshir each delivery company share, who owes the noncollecting Captain their retained delivery/purchase allocation, what authoritative evidence proves an actual transfer, and how unpaid obligations/refunds are attributed. No transfer, company custody, payout or settlement is inferred from designating a collector.

Pre-purchase cancellation retains the existing normal rules. Collected V3 invoice correction and direct reversal are blocked in PHP and PostgreSQL until an attributed customer refund/operating-balance correction contract is approved. Uncollected Invoice replacement/voiding continues through existing guards. No existing historical refund policy is repurposed to invent a V3 refund.

### Future owner-approved activation

1. Review the migration and single-only/correction limitations; obtain explicit activation approval and a fresh verified backup.
2. Verify the target owner database identity, existing financial prerequisites and whether the V3 migration is already registered. Do not repeat installation where applied (the owner reports batch 39 on `abshir_uat_v2`). Only an uninstalled target needs a separately approved path-specific migration. No owner migration or activation was performed during this routing correction.
3. With separate approval, enable `ORDER_ACCOUNTING_V3_SINGLE_CAPTAIN=true` for the local application, rebuild configuration cache if used and restart long-lived workers. Select exactly one eligible Captain in Wizard Step 3 for V3; selecting two uses V2, and leaving the selection empty uses V2. The migration alone does not change Order creation behavior.
4. Create a NEW fictional single-Captain CASH Order, issue and collect its Invoice and deliver normally. Confirm immutable version 3, exact frozen company-share debit, zero ordinary delivery Receivable/Custody, correct branch-scoped negative operating balance, and idempotent reconciliation.
5. Never change `ORD-20261013-00000001` or any historical binding. Disabling the flag affects future Orders only; existing V3 Orders continue using V3. Do not roll back the forward-only financial migration.
