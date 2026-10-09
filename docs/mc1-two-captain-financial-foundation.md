# MC1: physical shared purchases; settlement remains disabled

Baseline: `livewire-ux`, `02a56be`. This is a backend foundation, not authorization to activate two-Captain accounting in the Workflow. No route, Livewire action, permission, package or operational UI has been added.

## Sources implemented

`SharedOrderShopPurchaseService::record()` explicitly confirms a physical shared purchase, using the existing purchase source, permission, branch scope and private evidence service. It records one `order_shop_purchase_confirmations` header per Shop with `purchase_mode=SHARED_TWO_V1`, two immutable `order_shop_purchase_allocations`, and the explicitly designated active `PRIMARY_COLLECTION` assignment. The original single-payer field is NULL for shared purchases: it does **not** falsely attribute the whole payment to the collector. Each allocation identifies an actual payer through its immutable OrderCaptain assignment. No field says reimbursed or paid by the company.

Existing headers remain `SINGLE_PAYER`, with the original `order_captain_id`, evidence, dates and amount unchanged. Their original PostgreSQL validator is reused unchanged. The new service does not convert, infer or backfill old purchases. It cannot mix shared and legacy purchases on one Order, or attach shared sources to an Order with any Invoice, bill-payment request or cancellation intent.

The new source requires exactly two active assignments, one primary, one Currency, positive finite exact-precision money and purchase time after both assignments and Order entry. The amount is the operator-confirmed actual Shop purchase with supporting private evidence, **not** an estimate computed from the Invoice. The service derives exact half shares; it never accepts client-submitted allocation amounts. Later settlement must reconcile actual item totals and Currency against these confirmed physical amounts before issuing an Invoice.

PostgreSQL enforces source/Shop/Order/Branch/assignment identity, half-share precision, uniqueness and a complete two-row sum at transaction end. Header and allocation history cannot be updated or deleted. Existing purchase Currency-precision freezing also covers shared headers. Retry identity includes actor, amount, collector, time, metadata and evidence digest. Timestamp identity is normalized to seconds to match the existing source's `timestamp(0)` storage. Order -> Shop -> assignments -> Currency locks serialize retries and competing single/shared confirmation paths.

Evidence retains the existing `order_returns` private disk outside public/local serving roots. No public or temporary URL or new download route is introduced. `evidenceBytes()` reauthorizes permission, branch and nested ownership before checking stored bytes against MIME, size and SHA-256.

## Explicit disabled boundary

A shared purchase cannot currently enter legacy Invoice creation, delivery completion, customer collection, bill-payment processing, cancellation/refund, Captain replacement, repricing or ordinary Order mutation. Application guards give a controlled explanation; PostgreSQL guards protect alternate/direct SQL paths and parent reassignment. Normal single-payer Orders, including historical multiple-Captain assignment Orders, retain their previous financial contract.

The current CASH contract debits only the company delivery share from the primary Captain's **Deposit**; product costs and eligible entitlement are retained outside that ledger flow. The current SHAM_CASH contract has company-direct collection and creates product/entitlement Receivables from an Invoice-total funding split. Neither proves physical per-Shop purchase shares or the new full-CASH-through-company handover. Reusing those event identities would change their audited meaning.

The full shared contract therefore needs versioned collection/posting sources and an evidenced, attributed **full customer collection handover** source. A Captain Deposit receipt is working-capital money and is not that handover. Current bill payments separately track external principal custody/handovers and company fees; they must keep this distinction, while a future shared bill-payment source must bind to the same frozen collector. Existing bill completion currently accepts an active eligible participant, not exclusively the primary; this is not silently changed for historical/single-payer records.

Shared returns also need an immutable allocation of actual refunds to the original physical payers, including shortfall attribution. The existing return header has one refund recipient equal to its original single payer. It is deliberately blocked for shared sources, rather than redirecting both refunds to the collector. Collected-Invoice corrections and already-paid Receivables need the same source-version and recovery/disposition integration before activation.

## Exact accounting example for the future contract

Products: 1,000; physical costs: 500 per Captain. Delivery unit: 100; configured Captain share: 65%; two delivery units: 200. Combined discount: 20 **once**; customer delivery charge: 180. One customer Invoice: **1,180**, products charged once.

The designated collector physically collects 1,180. That full amount would increase this collector's attributed Cash Custody; a real evidenced 1,180 handover would decrease that Custody. A calculated or recorded purchase never proves this handover happened.

Each Captain is owed 500 purchase reimbursement plus their existing eligible 65 delivery entitlement = 565. Company owes 1,130 total; retained company share is 50. Each actual company payment requires the existing authorized, evidenced, source-attributed payout and reduces only the exact Receivable it settles. No Captain-to-Captain netting or transfer. No Deposit debit is added on top of full gross Custody. No separate duplicate shortfall credit is added on top of full entitlement: company-funded shortfall is already part of paying the entire eligible entitlement. Negative company net caused by an approved discount must remain visible under existing shortfall policy.

These movements are a design contract, **not ledger events implemented by MC1**. MC1 creates no accounts, ledger events, payouts, handovers, Invoice or Collections from physical purchase recording.

## Odd minor units require owner review

The existing historical Invoice funding split distributes leftover minor units in ascending OrderCaptain assignment ID order over the **aggregate product subtotal**. That convention does not determine who physically pays the remainder for each Shop. Two odd Shop totals can yield different payer totals under per-Shop versus aggregate distribution.

Proposed policy for review: allocate each Shop independently; divide into minor units, assign the one leftover unit to the lower frozen OrderCaptain assignment ID. Never ask an operator to distribute it manually. Owner approval is required before this financially consequential physical-payment policy is activated. MC1 currently rejects odd minor-unit shared purchases without writes; even amounts split exactly in currencies with 0, 2, 3 and 4 decimal places. It does not change Currency precision or round principal.

## Migration and activation

New forward-only migration: `database/migrations/2026_10_09_000000_add_shared_shop_purchase_foundation.php`. It adds the contract/collector columns and allocation table, makes the legacy payer nullable **only under the checked shared contract**, retains the old validation function and adds shared validation/completeness/boundary triggers. It never edits a committed migration, rewrites a historical purchase or removes financial configuration. `down()` refuses destructive rollback.

Applied and tested only on `abshir_testing`. Separate explicit owner approval is required before applying this migration to development `abshir`. The new service is not callable from normal UI, so the application continues to operate with its existing single-payer flow while this migration is pending there.

## MC2 prerequisite and UI plan

1. Approve the physical odd-minor-unit policy and shared CASH/SHAM_CASH collection-method contract.
2. Implement versioned shared collection, full customer handover, actual-payer reimbursement/entitlement posting, payout attribution, shared returns and corrected/partially-paid source handling. Use authoritative production services and PostgreSQL constraints; never repurpose old events or infer actual payments.
3. Prove physical sums match actual item pricing, one Invoice and one collector, two full existing delivery units/entitlements, combined discount once, collector consistency for external bills, immutable source snapshots, branch isolation and all relevant races.
4. Replace the temporary shared boundary only in a reviewed forward migration once the complete new contract is enforceable. Historical contracts remain frozen.
5. Then integrate assignment of one/two Captains and collector designation in Workflow, with no accounting allocation or settlement forms in normal Order execution. Render both Captains in the one Invoice from immutable identity snapshots. Preserve the canonical Workflow and read-only summary.

Until these prerequisites pass, shared execution and accounting remain disabled. MC1 is complete only as the explicitly bounded physical purchase foundation.

## Overnight MC2 integration, 2026-10-09

The subsequent MC2 work adds explicit, immutable participant/collector selections and integrates them with the Wizard and Workflow. See `docs/mc2-mc3-implementation.md` for the precise implemented boundary. MC1 source rows, private evidence, exact halves and its forward-only migration remain unchanged.

The owner has now explicitly approved **full shared CASH through Abshir**, reimbursement plus eligible delivery entitlement owed separately to both Captains, and no direct Captain-to-Captain settlement. That business policy is no longer awaiting approval. Its new authoritative collection/handover/posting/payout contracts still need implementation; the old CASH Deposit-debit events must not be repurposed. The only unresolved physical split policy is the recipient of a per-Shop odd minor unit.

The new read-only financial review reconciles every physical Shop allocation against actual item amounts and existing two-unit delivery snapshots. It creates no Invoice, Collection, Account, ledger event, handover or payout. Selected shared Orders cannot invoke old single-payer financial paths even before a purchase exists; historical unversioned assignments retain their old contract. No migration has been applied to `abshir` by this work.

## Focused verification

On `abshir_testing`: shared-purchase feature tests **14 passed / 113 assertions**; independent-process contention plus upgrade tests **3 passed / 23 assertions** (two real races: **2 / 17**; historical migration: **1 / 6**). Selected existing pricing, collection, posting, payout, return and bill-payment regressions: **20 passed / 184 assertions**. These are **37 distinct cases / 320 assertions**, not a full-suite run.

Both competing production services were observed in PostgreSQL's actual blocking graph behind the locked Order before release. Tests cover identical retries, conflicting shared/single confirmation, invalid direct SQL money/allocations, deferred completeness, private evidence cleanup after late failure, parent reassignment, immutable history, authorization, and unchanged original financial behavior. Scoped Pint, isolated Blade compilation, domain translation parity and `git diff --check` passed. No development database command, staging, commit or push was performed.
