# Simplified MC4 — simple shared Order execution, development only

Baseline: livewire-ux / 330bd8d. This work is not owner-database activation, release, merge approval or browser UAT.

## Owner scope and operator flow

Select one or two Captains; explicitly select the collector for two. Record each Shop purchase once using the existing evidence action; separately confirm real pickup. Use the existing legal status transitions, generate one Invoice, and record its actual customer Collection. Products are charged once, delivery has two units, and the authorized combined discount applies once. No split percentages, remainder-recipient choice, reimbursement amounts, payout distributions or cash handover form is required.

Equal physical purchase funding and off-system company settlement are company policy. The lowest immutable numeric assignment ID receives an indivisible Currency minor unit per Shop. An image is reused only after explicit dual-purpose confirmation; purchase and receipt remain separate authoritative records. Upload never invents pickup or delivery.

The committed MC1–MC3 selection/history/read-only review and demo-reset implementation remain. The financial review is an optional read-only diagnostic, not a settlement step or posting authority. MC5 and invoice_cash_handovers are not implemented or planned by this task.

## Necessary internal integrity retained

- Actual per-Shop immutable funding allocations preserve who physically paid and exact minor-unit arithmetic. Zero shares are possible for a one-minor-unit total, never zero ledger entries. Historical allocations are not rewritten.
- SharedOrderInvoiceSourceService binds one Invoice to confirmed purchases, actual item prices, the same Currency, frozen participants/collector and existing delivery pricing. Its issued financial snapshot uses the sums of per-Shop allocations, not a new split of the combined subtotal. Both Captains and the collector appear on the Invoice/print view.
- SharedOrderFinancialPostingService is an internal adapter to the existing FinancialPostingService and existing accounts. It records actual collection and earned unpaid obligations; it is not a payment engine, handover action or operator settlement workflow.
- CASH records full real Invoice collection in only the frozen collector's CAPTAIN_CASH_CUSTODY. SHAM_CASH records direct company collection with no Captain Custody. No Deposit movement, netting or imagined cash handover is posted.
- Reimbursement and full eligible delivery entitlement become CAPTAIN_COMPANY_RECEIVABLE only after issued Invoice, actual Collection AND DELIVERED. They remain unpaid liabilities, not evidence that the company paid either Captain. Discounts and company-funded shortfalls retain existing formulas; no duplicated entitlement or product charge is added.
- Canonical sources are INVOICE_COLLECTION / actual Collection ID / version 1 / frozen snapshot hash. PostgreSQL checks exact source, Order/Branch/Currency/actor, entries, amounts, timing and complete atomic sets. Failures roll back the triggering state and new ledger records. Order -> Invoice/Collection -> Currency -> Accounts lock ordering and production-service concurrency remain.
- Purchases bind business/pricing/participants; only genuine legal operational milestones remain mutable. Delivery requires all actual purchases, pricing, pickups and receipts. Generic reversals, shared refund/correction and bill-payment paths remain fail-closed.

These records are necessary for the existing Captain dashboard to distinguish actual cash custody and unpaid obligations. Off-system settlement is not automatically reflected in software balances: balances remain the recorded facts until an existing separately supported real source can account for a movement. Delivery does not require clearing balances. Nothing marks earnings as paid based on company policy alone.

## Settlement complexity removed

- Removed the uncommitted 060000 payout-source migration, which extended existing per-Captain payout allocation screens to shared claims.
- Removed shared events from selectable payable source lists. Existing payout authorization, evidence, limits and PostgreSQL whitelist are unchanged.
- Shared unpaid claims are recognized as known non-allocatable liabilities in the existing balance reconciliation query. This prevents them from blocking an unrelated legitimate single-Order payout on the same Captain/Currency/Branch, without enabling shared payouts.
- Removed tests performing shared partial payouts and the proposed future Invoice handover process from this document/backlog. New regressions instead prove no automatic payout/handover, refusal of shared payout attempts with zero writes, and preservation of unrelated existing payouts.
- Arabic/English messages no longer claim a handover workflow must be activated before normal shared Order execution.

## Migrations actually required

Committed foundation, preserved verbatim:

1. 2026_10_09_000000_add_shared_shop_purchase_foundation
2. 2026_10_09_010000_add_order_captain_selections
3. 2026_10_09_020000_guard_selected_shared_order_financial_paths

Uncommitted additive, forward-only integrity migrations:

4. 2026_10_09_030000_allocate_shared_purchase_minor_unit_remainders — automatic exact per-Shop remainder policy.
5. 2026_10_09_040000_bind_shared_invoices_to_actual_purchase_allocations — accurate immutable Invoice source and selected shared Invoice legality.
6. 2026_10_09_050000_post_shared_collection_and_captain_obligations — real collection/unpaid-claim source validation, atomicity and legal operational progress.

060000 is not part of the final migration chain. No new MC4 table, handover source, payment permission or package is introduced. No committed migration was edited. The test harness applies this chain to a new disposable schema in verified abshir_testing; editing an already-applied migration is never assumed to rerun it. No migration was applied to abshir or the owner preview. Any development activation still requires separate owner approval.

## Deliberately unsupported transitions

- Shared external bill-payment execution: the legacy single-Captain principal/fee/collector financial contract cannot safely be reused for this selected shared contract. Single/legacy bill fees, including SYP nearest-5 HALF_UP, remain unchanged.
- Shared after-purchase cancellation/returns, Invoice void/replacement/correction, generic reversals and recovery: exact original-payer refund sources are not implemented. They remain blocked rather than fabricating money movements. Before-purchase cancellation and authorized pre-binding selection changes remain available.
- Shared receivable payouts and Invoice cash handovers: outside this task by owner decision; no required operator step or fake ledger decrease substitutes for them.

Normal shared purchase -> Invoice -> customer Collection -> DELIVERED works without these exceptional/settlement paths. This does not claim support for every exceptional financial action or a fully reconciled off-system settlement.

## Dashboard and reusable demo reset

The existing authenticated Captain self-view uses actual balances, one distinct delivered Order and frozen eligible entitlement sources; simulations remain read-only. Employee Branch scoping is preserved. Tests cover both participants, collector-only cash and tampered self-view IDs.

MC4 adds no table. Its actual events retain the already-supported INVOICE_COLLECTION source in OrderResetMaintenance; shared allocation/selection dependencies are already in the committed reset allowlist. FK discovery, active delete guards and unattended/testing execution refusal are covered. No reset was run against abshir, abshir_cleanup_preview or a spoofed test database. Destructive reset acceptance on an activated schema remains deferred because the existing safe destructive harness intentionally targets owner databases/public schema. Do not weaken identity guards to claim acceptance.

## Verification boundary

Focused PostgreSQL tests include real Livewire Workflow actions and HTTP purchase evidence, single-Captain and historical regressions, two delivery units/one discount/product principal, exact allocations, authorization, immutable snapshots, no fake settlement, idempotency, rollback and independent-process concurrency. No browser UAT or full suite is run. Exact final results are recorded in the owner report and below after checks complete.

Final automated evidence (2026-10-09):

- Selected 22-file PostgreSQL integration/legacy/security/concurrency run: **346 passed, 0 failed, 3530 assertions**, 974.354 seconds. Verified abshir_testing; the generated isolated schema/config were removed by the harness. This is not the full suite.
- Includes real Livewire collector selection, Workflow status/Invoice/Collection actions and HTTP dual-purpose evidence. Shared unsupported payout attempts produce controlled validation and zero writes. A real independent legacy single-Order payout succeeds on the same Captain/Currency/Branch while shared claims remain unpaid. Independent-process production-service purchase, Collection and delivery contention is covered.
- PHP syntax: **31 changed/new eligible PHP files PASS**. Scoped Pint --test PASS. Blade compilation **303 files PASS**. Arabic/English key parity: **shared_orders 37 / domain 312 PASS**. npm build PASS (existing dependency CSS length-typehint warning only). git diff --check PASS. No dependency changes.
- Development read-only verification: current_database()=abshir, transaction_read_only=on, Orders=0, MC migration batches applied=0, selection table absent. Neither development nor owner-preview data was modified. No demo reset, browser UAT, full-suite run, staging, commit, push, reset or stash occurred.

Recommendation: development checkpoint review is appropriate for the normal shared workflow and necessary internal integrity only. Suggested subject: Simplify shared captain order execution and invoice accounting. Owner-approved schema activation, exceptional shared refund/bill paths and consolidated UAT remain separate boundaries.
