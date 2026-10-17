# Special product delivery policies

Implementation against `24e6936` on `livewire-ux`. Owner databases and Toshiba were not changed. This is an owner-review checkpoint, not deployment or browser acceptance.

## Existing contracts retained

- Products are a global catalog. Product prices remain in the existing price-observation/history workflow; a delivery policy does not change purchase prices, confirmed invoice product prices or Shop commissions. Orders, Shop origins, Captain assignments and financial access remain branch-scoped.
- `OrderDeliveryPricingService` remains the pricing/version/override authority. Active class-specific origin/destination rules are still required, including fail-closed missing routes. Ordinary multi-Shop Orders retain MAX route pricing.
- `DeliveryAllocationService` remains the exact BigDecimal/currency HALF_UP allocator. Captain additional percentage points remain subject to the existing combined 100% limit and configuration locking.
- `InvoiceSnapshotService` and the existing financial-source snapshot establish the invoice/posting facts. Issuance checks special product sources against the captured delivery allocation. Posting still consumes immutable sources through the existing V1/V2/V3 services; no event, ledger account, cash transfer or payout mechanism was added.
- V3 is single-Captain only. Existing creation routing remains unchanged: supported shared Orders use V2. The designated PRIMARY_COLLECTION Captain is separate from each participant's entitlement and actual purchase responsibility.

## Administration and operational flow

SUPER_ADMIN configures the existing Product form: special flag, currency, configured product unit, positive base delivery fee, and DEFAULT / PERCENTAGE / FIXED allocation. Fixed Captain plus company shares must exactly equal the base fee. Percentage is 0–100; money must satisfy the currency's existing precision. Other catalog editors cannot submit these financial fields even when they have ordinary `products.update` permission.

Policy edits append immutable versions with creator/time; they do not overwrite a version. The selected version is captured when adding/replacing an Order item. Existing items keep their version after Product edits or deactivation. A new item requires an active Product and a policy in the Order currency. The current catalog policy is one currency per Product, not an invented exchange/conversion system.

The Wizard offers a special-product search filter, keeps ordinary items in the same Shop, displays the special fee/policy, and requires explicit whole-unit delivery assignments when two Captains participate. One Captain receives the complete quantity automatically; quantity alone never selects a second Captain. Review uses the same sources and assignments as creation. Existing audited override controls determine whether the requested extra fee is immediately effective or requires approval.

Workflow provides an authorized portion-assignment action before pricing freezes, explicitly labelled as repricing at the base fee. It resets any prior extra fee only through that explicit action. Ordinary product/quantity/price editing stays in its existing flow. Changing a special quantity makes the old delivery snapshot unusable for invoice issuance until repriced; it does not silently change an existing customer charge. Allocation displays explain frozen Product/policy identity, custom base shares, extra fee, and the standard base/additional percentages.

## Allocation and accounting

- DEFAULT special base: ordinary effective Captain percentage, including additional points.
- FIXED/PERCENTAGE special base: custom allocation replaces both ordinary and Captain additional percentages on that base.
- Explicit extra fee: ordinary base plus that particular Captain's additional points. Custom base and ordinary/extra pool are separately captured. Currency rounding applies to the standard pool once, and company collectible is the exact remainder under existing discount/shortfall semantics.
- One fixed base 250 = Captain 150 + company 100. An extra 25 at standard 50% gives Captain 162.50 / company 112.50 with two-decimal currency, or 163 / 112 with zero-decimal currency. At effective 60%, the result is 165 / 110, customer 275.
- Two explicit one-unit portions at 250 each, each increased by 25: customer delivery 550, Captain entitlements 165 each, company total 220 at effective 60% each. Different additional percentages are applied independently: 50% on one extra gives 162.50 / 112.50; 60% on the other gives 165 / 110.
- A hose/ordinary product in the same Shop adds product value, not an automatic route delivery charge. Multiple special products sum their attributable source fees.
- V3 CASH: the existing company-share operating-balance obligation applies to the sole Captain. V3 SHAM_CASH: existing product reimbursement and delivery entitlement remain separate from operating balance/custody.
- V2 shared CASH: existing delivery custody is attributed only to the designated collector; each participant's entitlement remains in the established receivable contract. V2 shared SHAM_CASH uses the confirmed physical purchase allocations plus each participant's entitlement. It does not invent V3-like deductions from both Captains or assume a transfer between them.
- Mixed customer credit continues to reduce actual payment, not original invoice value or contractual entitlement. No fictitious cash is collected. Existing cancellation, return, personal-funds credit payouts, commissions and benefit contracts were not redesigned.

## Explicitly unsupported combinations

1. **Owner-confirmed temporary restriction:** special products combined across multiple Shops are blocked. Same-Shop special plus ordinary products are supported; adding an ordinary item leaves the delivery fee unchanged. The owner clarified that a small add-on may be collected along the same trip with explicit supervisor approval: the special base must remain intact, MAX must not replace it, and only a manual increase uses the effective standard split. The current architecture has no authoritative classification distinguishing such approved cross-Shop add-ons from independent/distant pickups. Merely belonging to one Order is not approval. The smallest future change is an explicit Order-Shop pickup classification/approval action, recording the special delivery source and approving actor/time/reason, with snapshot and server validation; it is not a route-planning system. Until that change is separately reviewed, even a nearby second-Shop pickup stays blocked. Ordinary multi-Shop MAX is unchanged.
2. Fees/discounts reducing a participant below the configured special base are blocked pending an approved base-reduction allocation policy. Existing discounts against the extra portion retain their existing permissions and company-funded treatment.
3. Fractional quantities, alternate units and unequal per-Captain base fees are blocked. This flow supports whole units of the configured Product unit, maximum 999,999 units per attributable participant/source, and equal base fees per participant because the existing pricing header has one unit fee. No average, arbitrary split or unit conversion is invented.
4. Participant changes on an already-priced special Order may be rejected when existing portions no longer match participants. Select the intended participants and complete portions in the Wizard before creating a shared Order. This change does not introduce a new atomic reassignment/accounting workflow.
5. Shared V3 posting and per-noncollector V3 operating deductions are not implemented. They need a separately approved coherent accounting contract; existing V2 support is preserved.

## Database protections and activation

New forward-only migrations, in order after the committed Captain additional-percentage migration `2026_10_17_000000`:

1. `2026_10_17_010000_add_special_product_delivery_policies.php`: ordinary Products default false, immutable policy versions, nullable item policy/portion metadata and nullable allocation JSON. Restrictive FKs, exact fixed reconciliation, active administrator/currency and precision checks. No ledger/backfill/fee schedule changes.
2. `2026_10_17_020000_validate_special_delivery_allocations.php`: PostgreSQL independently recomputes source identity, product unit, quantity conservation, participant attribution, custom/standard money, extra, discounts and final amounts. It rejects duplicate/missing source portions and cross-Shop special allocations. It adds issued-source/metadata immutability and source-snapshot validation.

The second migration checks the installed `validate_delivery_fuel_snapshot()` definition before adding the special branch. The original ordinary validation and UPDATE immutability path remain in place; a different installed prerequisite stops activation transactionally. No financial posting function or historical migration is replaced. Forward recovery requires review; these migrations intentionally do not offer destructive rollback.

Both new migrations were executed only in fresh disposable schemas of verified `abshir_testing`, including an actual historical V1 fixture with posted invoices/collections/entries. Migration tests compare original money and source snapshots rather than assuming empty tables prove compatibility.

After owner review: take/restore-verify a backup, confirm the intended environment and installed `000000` prerequisite, then apply only the two named migration paths in order under a separate explicit authorization. Deploy matching code/assets. No permission additions, seeders, dependencies, automatic Product creation or activation are needed. Do not apply these migrations to an owner database as part of this implementation task.

## Focused verification

All database checks use `php tests/Support/run_isolated_pgsql.php`; it verifies `current_database() = abshir_testing` and creates disposable schemas. No complete suite, owner-database transactions, browser UAT, deployment package or Git mutation is claimed.

Focused selections:

- `SpecialProductDeliveryTest`: 26 distinct cases across targeted runs. Covers all three modes, special-base precedence, exact rounding, same-Shop ordinary additions, multiple special sources, explicit shared portions, different Captain percentages, the 275/275 example, source/currency/branch guards, SQL forgery/immutability, policy history, ordinary/credit CASH and SHAM_CASH posting, quantity-change staleness, and the established discount path.
- Existing `CaptainFuelAllowanceTest`, `DeliveryPricingTest`, `DeliveryPricingClassificationTest`, `CreateOrderWizardTest`, `SharedOrderWizardTest`, `ProductPricingTest`, `ProductPricingAuthorizationTest`, `OrderAccountingV3Test`, `MixedCustomerCreditTest`, `CustomerReturnCaseTest`, `CustomerCreditCashPayoutTest`: 274 cases in one run; 271 passed, 3 errored, 2,787 assertions. All three errors shared one integration cause: inline Product creation constructs `ProductRequest` to reuse rules without an HTTP user resolver. The new financial-field rules now handle that context as unauthorized (prohibited) instead of dereferencing null. No permission or financial rule was relaxed. Only those affected tests were selected for recheck.
- A subsequent targeted run of special-feature coverage, the historical V1 migration proof, eight existing single/shared Shop-commission posting cases and two benefit approval cases: **36 passed, 408 assertions**, no failures/errors/skips. The historical test applies the two new migrations over actual posted V1 fixtures and compares unchanged allocations, invoices, collections, source snapshots and ledger rows.
- The added special discount regression independently passed: **1 test, 6 assertions**. The last administration/Wizard/discount check before the final navigation assertions passed **3 tests, 34 assertions**. These are reruns/overlapping subsets, not extra unique tests.

Final affected-test recheck: the three inline Product creation regressions, positive administrator HTTP policy editing, special Wizard validation/back-and-forward/unchanged-Branch/double-submit preservation, and special discount handling: **6 passed, 76 assertions**, no failures/errors/skips. All three integration errors are resolved. Across the selections, 311 distinct cases were exercised; overlapping reruns are not additional unique tests. The full suite was not run.

PHP syntax (26 changed/new PHP files), scoped Pint, Blade compilation, Arabic/English translation key parity and `git diff --check` passed. `npm run build` passed; it emitted the pre-existing non-blocking Tailwind type-hint warning for `h-[length:var(--border)]`. No dependency or build configuration changed. Generated assets remain ignored by Git. Browser behavior of these new controls has not been visually accepted; PHP/Livewire tests do not establish real-browser timing stability.

## Changed files

Existing files:

```text
app/Http/Controllers/ProductController.php
app/Http/Requests/ProductRequest.php
app/Livewire/Orders/CreateOrder.php
app/Livewire/Orders/CreatesOrderWizard.php
app/Livewire/Orders/OverridesWizardDelivery.php
app/Livewire/Orders/SelectsWizardCaptains.php
app/Models/OrderDeliveryAllocation.php
app/Models/OrderItem.php
app/Models/Product.php
app/Services/DeliveryAllocationService.php
app/Services/InvoiceSnapshotService.php
app/Services/OrderCreationDraftService.php
app/Services/OrderDeliveryPricingService.php
app/Services/OrderItemService.php
app/Services/ProductService.php
resources/views/components/captains/delivery-share.blade.php
resources/views/livewire/orders/wizard-delivery.blade.php
resources/views/livewire/orders/wizard-review.blade.php
resources/views/livewire/orders/wizard-shops.blade.php
resources/views/livewire/orders/workflow.blade.php
resources/views/orders/_delivery-pricing.blade.php
resources/views/products/form.blade.php
routes/web.php
tests/Feature/OrderFinancialPostingTest.php
```

New files:

```text
app/Http/Controllers/SpecialDeliveryPortionController.php
app/Models/ProductDeliveryPolicy.php
app/Services/ProductDeliveryPolicyService.php
app/Services/SpecialProductDeliveryService.php
database/migrations/2026_10_17_010000_add_special_product_delivery_policies.php
database/migrations/2026_10_17_020000_validate_special_delivery_allocations.php
docs/special-products-delivery.md
lang/ar/special.php
lang/en/special.php
resources/views/components/orders/special-delivery-breakdown.blade.php
resources/views/orders/_special-delivery-portions.blade.php
resources/views/products/_special-policy.blade.php
tests/Feature/SpecialProductDeliveryTest.php
```

Pre-existing untracked deployment material, historical documents and UAT evidence are preserved and are not part of these 37 feature files. Nothing was staged, committed, packaged or sent to Toshiba.
