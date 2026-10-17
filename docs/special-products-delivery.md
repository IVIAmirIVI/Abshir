# Special product delivery policies

Initial implementation against `24e6936`, committed as `c9ee5dc` on `livewire-ux`. The focused completion below adds explicit supervisor-approved cross-Shop add-ons against that committed baseline. Owner databases and Toshiba were not changed. This is an owner-review checkpoint, not deployment or browser acceptance.

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

1. **Unapproved cross-Shop pickups remain blocked.** The initial blanket restriction has been replaced by the explicit approval mechanism documented below. Small ordinary add-ons from another Shop are supported only with a current supervisor approval linking their exact items to one special source Shop. Multiple special source Shops, independent/distant trips, stale approvals and implicit approval by Order membership are not supported. Ordinary multi-Shop MAX remains unchanged.
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

## Focused workflow completion after c9ee5dc

### Explicit approval

`SpecialAddonApprovalService` reuses `orders.update` and `delivery_pricing.apply`, requires an active authorized SUPER_ADMIN/ORDER_EMPLOYEE and the existing Order/Branch access checks, and participates in the Order row-lock/transaction protocol. No new permission, seeder, ledger event or account is introduced.

In Wizard Step 2, after adding the ordinary items of the additional Shop, the supervisor selects the related special item, records why the pickup is a practical small add-on on the same trip, and explicitly approves the displayed items. Approval is held in locked Livewire state; public input fields do not constitute approval. Back/forward preserves it. Altering the draft or destination invalidates it. Creation atomically saves real item/Shop IDs, the approval, pricing and the existing audited override, so failure rolls the whole Order back. Removed special sources cannot cause an old approval to be reused for an ordinary Order.

The existing Workflow Captain/pricing panel provides the same explicit approval action for editable Orders. It displays current approval and recent immutable approval history; it does not reprice or increase the fee merely upon approval. Changed details require a new record, not mutation of history. The button's declaration is the supervisor's human decision about a small same-trip pickup; there is no automated distance, size, product-name or route inference.

The new `order_special_addon_approvals` table stores:

- Real Order, additional Order-Shop and source special Order-item IDs.
- Server-selected approving user, approval timestamp and mandatory short reason.
- An immutable JSON snapshot of the additional Shop/origin and **every currently approved item** (ID, Product, name, unit and quantity), source special policy/quantity/origin, Branch and destination.
- A digest used only for idempotency/indexing. Authorization and freshness compare the actual JSON facts, not a trusted client hash.

PostgreSQL rejects unauthorized/foreign-Branch actors, wrong source ownership, special products masquerading as add-on Shops, empty or changed facts, frozen Orders, and approval mutation/deletion. Allocation snapshots carry the exact immutable approval IDs; the independent financial validator verifies those IDs and current facts before invoice finalization. This is approval evidence, not financial approval or a cash transaction.

### Pricing and financial boundary

For approved add-ons, the special source route is the selected pricing reference instead of the maximum-priced add-on route. The existing `MULTI_SHOP` classification and required active class-specific route rules remain in place as references/eligibility checks; missing rules still fail closed. No rule is automatically created, and a reference route fee is not an extra customer charge. Normal Orders still select MAX.

The special fee itself still comes only from its immutable Product policy. A reference add-on route priced at 999 does not replace a configured 250 special fee. Approval without a supervisor fee increase remains 250 = 150 Captain + 100 company. An authorized +25 uses only the existing effective standard split on the extra amount: at 60%, 275 = 165 + 110. Existing override thresholds and pending administrative approval remain authoritative.

Two explicit portions remain 275 each / 550 total / 165 entitlement each / 220 company allocation. Existing V2 CASH posts delivery custody 550 to the designated collector only, and receivable entitlement 165 per participant; neither participant gets an invented V3 operating debit or an implied transfer. Shared SHAM_CASH uses the existing confirmed physical purchase allocations: with 260 total products in the acceptance fixture, each actual 130 purchase share plus 165 entitlement is 295 receivable. V3 single CASH retains the sole Captain's 110 company-share operating debit. Product principal is counted once in one invoice. Company allocation/custody is not proof of company cash receipt or settlement.

No V1/V2/V3 posting service, company settlement policy, customer credit/return calculation, historical issued invoice or Captain payout contract was changed.

### Additive migration and release steps

`2026_10_17_161740_add_supervisor_approved_special_addons.php` runs after the committed `000000`/`010000`/`020000` migrations. It adds the approval table/FKs/index and guarded SQL functions, then checks and replaces only the old cross-Shop prohibition inside the installed special-allocation validator. Unexpected prerequisite definitions stop activation transactionally. The ordinary allocation validator, monetary formula, financial posting functions and old migrations remain intact. It is forward-only because the approval history must not be deleted on rollback.

It was tested only in disposable `abshir_testing` schemas, including actual posted V1 history. No owner database was inspected for activation or migrated. After owner code review/commit, packaging must include this migration and matching code/assets. A separately approved backup/restore and path-specific migration is required before use on a target database. No authorization seeding is needed. Do not run reset, deployment or Toshiba operations as part of this task.

### Incremental files

New: `app/Services/SpecialAddonApprovalService.php`, `app/Http/Controllers/SpecialAddonApprovalController.php`, the migration above, `tests/Feature/SpecialAddonApprovalTest.php`, `resources/views/livewire/orders/wizard-special-addons.blade.php`, `resources/views/orders/_special-addons.blade.php`.

Modified: `CreateOrder`, `CreatesOrderWizard`, `SpecialProductDeliveryService`, `OrderCreationDraftService`, `OrderDeliveryPricingService`, `DeliveryAllocationService`, `routes/web.php`, both `special.php` translations, Wizard Shop/Review views, Workflow/pricing views, `OrderFinancialPostingTest`, and this document. The allocator change carries approval IDs only; it does not change financial arithmetic.

The project-required `pint --dirty --format agent` also formatted the pre-existing untracked local `deployment/windows/Read-Identity.php` (imports/spacing only). That script was never executed; the deployment directory remains outside the proposed feature commit. No untracked deployment or UAT evidence file was deleted, and no Toshiba files were accessed.

### Current acceptance evidence

The first new-test run exposed a PL/pgSQL record/SQL-alias collision; renaming the local record fixed it. A second fixture error called a nonexistent rule-service `save` method; the fixture now uses the actual existing `create` action. The subsequent 11-case run passed 10 cases (including all four financial posting scenarios); its only failure asserted that the default Workflow panel rendered the Captain/pricing approval panel. The rendering check now explicitly opens the intended existing panel.

The focused integrated run then passed **89 tests / 944 assertions / 0 failures / 0 errors / 0 skipped** through `tests/Support/run_isolated_pgsql.php`. Selection: `SpecialAddonApprovalTest`, `SpecialProductDeliveryTest`, `DeliveryPricingTest`, `SharedOrderWizardTest`, the two unchanged-Branch/ordinary multi-Shop Wizard regressions, and the V1 forward-migration preservation test. This includes preserved normal MAX behavior, snapshot immutability, exact currency rounding, different Captain additional percentages, unauthorized/foreign-Branch rejection and idempotent posting. It is not the full regression suite.

The final targeted Wizard check passed **2 tests / 30 assertions / 0 failures / 0 errors / 0 skipped**: one rechecks approved shared creation/back-forward/double-submit, and the additional case removes the special item after draft approval and confirms an ordinary Order uses MAX 999 without persisting the stale add-on approval. These two cases overlap one case of the 89-test selection; they are not a second complete regression run.

Final PHP syntax (14 scoped files), scoped Pint, Blade compilation, Arabic/English translation-key parity (covered by `SpecialProductDeliveryTest`) and `git diff --check` passed. `npm run build` passed with the same pre-existing Tailwind `h-[length:var(--border)]` type-hint warning. No dependency changes were made. Git remains `livewire-ux` / `c9ee5dc`, with 15 modified feature/documentation/test files and 6 new feature files; nothing is staged. Prior untracked material remains outside the proposed feature commit.

Browser workflow acceptance is **NOT VERIFIED**: no disposable isolated browser application has been provisioned for this new migration, and browser transactions on protected owner databases are prohibited in this task. PHPUnit/Livewire rendering and action tests do not establish browser timing or visual acceptance. No protected database is migrated or used for browser transactions to obtain that evidence. No confirmed financial blocker remains within the supported tested contracts; owner review, separately authorized migration activation and actual UI acceptance remain necessary before use.
