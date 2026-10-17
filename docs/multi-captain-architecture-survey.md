# Multi-Captain architecture survey — UX batch, 2026-10-08

Read-only domain survey on livewire-ux, baseline 7fe5e11. No multi-Captain behavior or financial schema was changed by this batch.

## Existing assignment behavior

`OrderCaptainService::assign()` supports multiple active Captains. It prevents duplicate active participation, requires PRIMARY_COLLECTION before SUPPORT, and allows exactly one active PRIMARY_COLLECTION. It does not impose a one- or two-Captain maximum. The PostgreSQL partial indexes in `2026_09_15_010000_create_order_core_tables.php` enforce one active primary and one active row per Order/Captain, preserving removed assignment history.

`CaptainAssignments` chooses SUPPORT when a primary already exists and excludes already-assigned Captains. Its successful mutation redirects to Workflow. `OrderWorkflowState::describe()` considers the Captain step satisfied as soon as a primary and delivery pricing exist, and then directs the operator to Shops. This explains why the normal progression stops offering assignment after the first Captain; it is a presentation/completion criterion, not a database one-Captain limit. The Captain step can be revisited only within existing mutation permissions and lifecycle guards. This conclusion is from source inspection, not a claim that browser UAT was reproduced.

Assignment and switching require existing permissions and branch/operational eligibility. The shared Order mutation guard blocks changes after DELIVERED, CANCELLED, cancellation intent, or a current ISSUED Invoice. Historical source contracts impose additional integrity checks. A proposed extension must not reopen those boundaries.

## What already supports multiple Captains

`OrderDeliveryPricingService` uses active Captain count: gross delivery charge is applied unit fee multiplied by that count. `DeliveryAllocationService` keeps each Captain's entitlement as unit fee × configured Captain-share percentage. It applies one combined discount, distributes discount minor units deterministically, and preserves company_collectible/captain_shortfall. Thus two delivery units, two company shares before discount, and one combined discount are already represented. “Full eligible entitlement” is the configured entitlement, not automatically 100% of the customer delivery fee.

`InvoiceSnapshotService` still builds one Invoice with product lines counted once and the combined delivery amount. Its normal primary identity is the PRIMARY_COLLECTION assignment. `OrderFinancialSourceSnapshotService` freezes all participating Captain allocations and the historical primary. It currently splits product subtotal minor units equally, assigning residual units by allocation ordering. This is a financial allocation; it does not prove who physically paid at each Shop.

## Required changes by subsystem

| Subsystem | Current authoritative representation | Required second-batch change |
| --- | --- | --- |
| Physical Shop purchase | One immutable `OrderShopPurchaseConfirmation` per Shop, one `order_captain_id`, full amount and evidence. `OrderShopPurchaseConfirmationService::record()` rejects a second confirmation. | Represent the two actual payers with immutable per-Captain allocations whose sum equals the Shop's confirmed total. Keep one purchase total/evidence boundary; do not record the full cost twice. Validate the approved precision policy in application and PostgreSQL. |
| Delivery | Unit fee × Captain count; per-Captain allocation; one combined discount. | Retain these formulas and snapshot rules. Make participation and collector choice explicit in Workflow before freezing. Add end-to-end two-Captain regressions rather than a parallel pricing engine. |
| Invoice | One current Invoice, immutable versioned facts; existing financial snapshot includes all Captains. | Display both participants using immutable identity snapshots, with one total and one authoritative collector. Determine whether existing identity snapshots suffice for durable printed names. |
| Collection | CASH requires full payment and the authoritative PRIMARY_COLLECTION Captain. SHAM_CASH is direct-to-company, with no fictitious Captain cash collection. | Supervisor explicitly selects primary from assigned Captains before freeze. Preserve full-payment-only, one collection, branch authorization and idempotency. Keep SHAM_CASH's physical collection meaning distinct. |
| Cash Custody | Corrected normal Order flow records only Abshir's company delivery money held by the primary, not the full Invoice. | Preserve company-only Custody. Account for physical product cost paid by SUPPORT and the customer money received by PRIMARY without duplicating gross Custody or product charges. Explicitly define the inter-Captain reimbursement/settlement obligation before implementing it. |
| Deposit | Corrected CASH collection debits the primary's Deposit for company delivery share; existing external-payment principal handover uses its own source contract. | Preserve source-specific events and branch balances. Do not treat ordinary product funding as bill-payment principal or create Deposit/Custody movements merely because a split is displayed. |
| Receivable | SHAM_CASH reimburses frozen product funding shares and delivery entitlements per Captain. CASH records company-funded delivery shortfalls. | Derive product reimbursement from actual payer allocations. CASH needs an approved settlement path for SUPPORT's product funding and retained delivery entitlement when PRIMARY receives all customer cash. Link corrections, payouts and recoveries to those immutable source allocations. |
| External bill payment | `OrderBillPaymentService::complete()` currently permits an active participating Captain assigned by delivery, not exclusively PRIMARY_COLLECTION. | Require the frozen authoritative collecting Captain in service, forms and PostgreSQL source contract. Keep service fee company revenue, principal and fee distinct, completion evidence and idempotency unchanged. |
| Returns/corrections | Durable source identity, financial snapshots, reversal linkage and attributed payouts/recoveries. | Extend source identity/version and hash validation for the new purchase allocations. Refund/return compensation must follow actual payer and collector facts, preserve exact reversals and leave historical versions untouched. |

## Migration requirement and compatibility

A minimal additive migration is required to represent durable physical purchase allocations; existing single-payer confirmation cannot truthfully represent two physical payments. New allocation constraints/triggers and financial snapshot version handling must be designed together. Reuse existing assignment roles and financial accounts where their meaning matches. Do not add an invented second Invoice, duplicate ledger engine, or infer/rewrite historical physical payments. Legacy confirmations remain single-payer records with their original contracts. Any deployment to development requires separate approval.

## Owner decisions required before financial implementation

1. **Indivisible minor units:** SYP at zero decimal places cannot split 101 SYP into exactly 50.5/50.5. Choose explicit residual allocation or reject such purchases; do not change Currency precision or silently borrow the current Invoice-subtotal remainder allocation. Decide whether rounding is per Shop, per product line, or total Order, and who pays the residual.
2. **CASH settlement between Captains:** PRIMARY receives all customer money while SUPPORT paid half the products and has a delivery entitlement. Define who owes SUPPORT, when the transfer occurs, and what evidence settles that obligation. An equal split alone does not physically reimburse SUPPORT. Decide treatment of company-funded shortfall separately.
3. **Participant count and timing:** Exactly two maximum or permit more? Define the cutoff for adding/removing/switching participants and collector after physical purchases. Existing frozen history must stay closed.
4. **Returns and corrections:** Who receives Shop refunds, customer refunds, and reimbursement recovery when both paid? Specify attribution and evidence requirements, including one Captain leaving the Order after payment.
5. **Collector and SHAM_CASH:** Confirm collector choice is still recorded for bill payments when the customer Invoice is paid directly to company; preserve absence of Captain CASH Custody for that method.
6. **Entitlement interpretation:** Confirm retaining the full eligible entitlement means the existing configured Captain-share calculation. Changing it to the entire delivery fee would be a separate pricing policy.

## Scoped second-batch plan

1. Resolve the decisions above with worked even/odd-cost CASH and SHAM_CASH examples and exact conservation equations.
2. Add explicit multi-Captain/collector Workflow controls using existing assignment service and branch-scoped ORDER_EMPLOYEE permissions; do not relax frozen guards.
3. Add immutable Shop payer allocations with sum, ownership, precision, eligibility and historical compatibility constraints; extend authoritative purchase action and evidence UI.
4. Version source snapshots and hashes to bind actual payer shares and collector; keep one Invoice and render immutable participant identities.
5. Extend existing posting/settlement and bill-payment collector contracts, ensuring every movement has one source and exact idempotency/reversal linkage. Preserve current delivery/discount formulas.
6. Test two-Captain purchases, collector switching limits, odd-unit policy, CASH/SHAM_CASH, external bills, discounts/shortfalls, cancellations/returns/corrections, branch access and independent PostgreSQL races. Review migration on testing only before any development approval.

This survey does not authorize or implement the second batch.

## Phone and product-total UX acceptance

Implemented in this batch:
- Inline Wizard mobile creation through CustomerPhoneService, immediately selecting the saved number while preserving Customer, address, Branch, source, notes, Shops and products.
- Strict mobile raw input 09 plus eight ASCII digits; general telephone fields retain landline behavior. Existing international/otherwise incompatible mobile rows remain selectable and unchanged; saving a new or edited mobile requires the approved local format. No new global uniqueness/schema constraint.
- BigDecimal line and Shop subtotals using Invoice line HalfUp precision. Estimated and actual values remain distinct; missing prices yield unavailable instead of guessed/partial totals; different currencies stay separate. Reactive editing previews are read-only and submit only original authoritative inputs, not a client total.

Final verification: 143 focused PostgreSQL tests passed, 3520 assertions (CreateOrderWizardTest, OrderLivewireUxTest, OrderSearchSelectionTest, OrderWorkflowTest, CustomerManagementTest, OrderCustomerAreaLocalizationTest). No full suite. Scoped Pint PASS; Blade compilation PASS in isolated testing cache; common/orders/customers/areas/captains/shops/catalog/pricing/order_ux Arabic-English structural parity PASS; git diff --check PASS. Browser/manual UAT was not run in this batch.

Read-only development aggregate: customer_phones 5 populated / 1 incompatible mobile; shops 4 / 0; branches 1 / 0; order_shops 14 / 0. Inspection ran inside an explicitly read-only PostgreSQL transaction on abshir. No row values disclosed, rewritten or deleted. No development migrations, seeding or business writes. No staging, commit or push. Branch livewire-ux; HEAD remains 7fe5e11.

Manual retest:
1. Build a Wizard draft with Customer/address/Shop/product, return to Step 1, use إضافة رقم هاتف and save 0951234567. Confirm it is selected immediately; go forward/back to Review and confirm the rest of the draft survives.
2. Try spaces, letters, Arabic digits, wrong length and same-Customer duplicate; expect validation with the draft intact. Confirm landline editing and legacy number selection still work, and unauthorized/cross-branch access is denied.
3. In Workflow product editing set quantity 4 and applicable unit price 50: expect line total 200 in that Currency. Change quantity/price/Currency and verify preview; confirm actual price using the existing action. Summary should show estimated and actual totals separately, with incomplete Shop totals unavailable.
4. Repeat on desktop/mobile, including immediate save/navigation. Preview changes alone must not persist quantity/price or generate an Invoice/financial event.

Exact files touched by this batch (other Git changes pre-exist and are preserved):
```
app/Http/Requests/Admin/BranchRequest.php
app/Http/Requests/CustomerPhoneRequest.php
app/Http/Requests/OrderShopRequest.php
app/Http/Requests/ShopRequest.php
app/Livewire/Orders/AddShop.php
app/Livewire/Orders/CreateOrder.php
app/Livewire/Orders/CreatesOrderWizard.php
app/Livewire/Orders/ItemLinePreview.php
app/Livewire/Orders/OrderShopItems.php
app/Rules/MobilePhone.php
app/Services/CustomerPhoneService.php
app/Services/OrderLineTotalService.php
app/Services/OrderShopService.php
app/Services/ShopService.php
lang/ar/order_ux.php
lang/en/order_ux.php
resources/views/admin/branches/form.blade.php
resources/views/customers/phones/form.blade.php
resources/views/shops/form.blade.php
resources/views/livewire/orders/add-shop.blade.php
resources/views/livewire/orders/create-order.blade.php
resources/views/livewire/orders/item-line-preview.blade.php
resources/views/livewire/orders/order-shop-items.blade.php
resources/views/livewire/orders/wizard-modals.blade.php
resources/views/livewire/orders/wizard-shops.blade.php
resources/views/components/orders/line-totals.blade.php
resources/views/components/orders/shop-totals.blade.php
resources/views/orders/_shop-items-summary.blade.php
 tests/Feature/CreateOrderWizardTest.php
 tests/Feature/CustomerManagementTest.php
 tests/Feature/OrderLivewireUxTest.php
 tests/Feature/OrderCustomerAreaLocalizationTest.php
docs/multi-captain-architecture-survey.md
```

The localization regressions were aligned with the existing summary-only Workflow (labels and automatic sole-contact selection); legacy number search/visibility coverage remains. New-save duplicate fixtures now use valid local mobiles; landline normalization retains separate regression coverage.

Complete final `git status --short` (includes preserved earlier batches):
```
 M app/Http/Controllers/BillPaymentFeeRuleController.php
 M app/Http/Controllers/InvoiceCollectionController.php
 M app/Http/Controllers/InvoiceController.php
 M app/Http/Controllers/OrderController.php
 M app/Http/Controllers/ShopController.php
 M app/Http/Requests/Admin/BranchRequest.php
 M app/Http/Requests/CustomerPhoneRequest.php
 M app/Http/Requests/OrderShopRequest.php
 M app/Http/Requests/ShopRequest.php
 M app/Livewire/Orders/AddShop.php
 M app/Livewire/Orders/CaptainAssignments.php
 M app/Livewire/Orders/CreateOrder.php
 M app/Livewire/Orders/NextAction.php
 M app/Livewire/Orders/OrderShopItems.php
 M app/Models/BillPaymentFeeRule.php
 M app/Models/Shop.php
 M app/Models/ShopType.php
 M app/Services/BillPaymentFeeRuleService.php
 M app/Services/CustomerPhoneService.php
 M app/Services/InlineOrderProductService.php
 M app/Services/InvoiceSnapshotService.php
 M app/Services/OrderBillPaymentService.php
 M app/Services/OrderDeliveryPricingService.php
 M app/Services/OrderShopService.php
 M app/Services/ShopService.php
 M lang/ar/orders.php
 M lang/en/orders.php
 M resources/views/admin/branches/form.blade.php
 M resources/views/bill-payment-fees/index.blade.php
 M resources/views/customers/phones/form.blade.php
 M resources/views/invoices/show.blade.php
 M resources/views/livewire/orders/add-shop.blade.php
 M resources/views/livewire/orders/create-order.blade.php
 M resources/views/livewire/orders/order-shop-items.blade.php
 M resources/views/orders/_bill-payments.blade.php
 M resources/views/orders/_delivery-pricing.blade.php
 M resources/views/orders/_invoices.blade.php
 M resources/views/orders/_phase85.blade.php
 M resources/views/orders/_phase9c2.blade.php
 M resources/views/orders/_summary.blade.php
 M resources/views/orders/show.blade.php
 M resources/views/shops/form.blade.php
 M routes/web.php
 M tests/Feature/CustomerManagementTest.php
 M tests/Feature/DeliveryPricingClassificationTest.php
 M tests/Feature/DeliveryPricingTest.php
 M tests/Feature/InvoiceAndCollectionTest.php
 M tests/Feature/OrderBillPaymentConcurrencyTest.php
 M tests/Feature/OrderBillPaymentTest.php
 M tests/Feature/OrderCustomerAreaLocalizationTest.php
 M tests/Feature/OrderLivewireUxTest.php
 M tests/Feature/OrderSearchSelectionTest.php
?? app/Livewire/Orders/BillPaymentFeePreview.php
?? app/Livewire/Orders/CreatesOrderWizard.php
?? app/Livewire/Orders/ItemLinePreview.php
?? app/Livewire/Orders/Workflow.php
?? app/Rules/
?? app/Services/OrderCreationDraftService.php
?? app/Services/OrderLineTotalService.php
?? app/Services/OrderWorkflowData.php
?? app/Services/OrderWorkflowState.php
?? database/migrations/2026_10_08_000000_add_business_classification_to_shops.php
?? database/migrations/2026_10_08_010000_add_tiered_bill_payment_fees.php
?? docs/
?? lang/ar/bill_fees.php
?? lang/ar/order_ux.php
?? lang/en/bill_fees.php
?? lang/en/order_ux.php
?? resources/views/bill-payment-fees/fields.blade.php
?? resources/views/bill-payment-fees/policy.blade.php
?? resources/views/components/orders/
?? resources/views/livewire/orders/bill-payment-fee-preview.blade.php
?? resources/views/livewire/orders/item-line-preview.blade.php
?? resources/views/livewire/orders/wizard-delivery.blade.php
?? resources/views/livewire/orders/wizard-modals.blade.php
?? resources/views/livewire/orders/wizard-review.blade.php
?? resources/views/livewire/orders/wizard-shops.blade.php
?? resources/views/livewire/orders/workflow-cancellation.blade.php
?? resources/views/livewire/orders/workflow-invoice.blade.php
?? resources/views/livewire/orders/workflow-shop.blade.php
?? resources/views/livewire/orders/workflow.blade.php
?? resources/views/orders/_shop-facts.blade.php
?? resources/views/orders/_shop-items-summary.blade.php
?? resources/views/orders/_shop-purchase.blade.php
?? resources/views/orders/workflow.blade.php
?? tests/Feature/CreateOrderWizardTest.php
?? tests/Feature/OrderWorkflowTest.php
```
