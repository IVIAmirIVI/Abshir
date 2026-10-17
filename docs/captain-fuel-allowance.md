# Additional Captain delivery share

Implemented on `livewire-ux` after `a6cd54f`. Owner-database activation is not part of this change.

## Existing contract and integration

- The base percentage is the global `CAPTAIN_DELIVERY_SHARE_PERCENT` setting resolved by `DeliveryPricingSettings`. Route/branch/class rules and audited overrides determine the eligible delivery unit fee, not a separate Captain percentage.
- `OrderDeliveryPricingService` creates a pricing version when pricing, discounting or changing participants. It reads the participating Captains under shared row locks and supplies their allowance percentages to the existing `DeliveryAllocationService`.
- Each participant retains their own eligible delivery unit fee in a shared Order. Fuel is applied to that unit fee once. The existing single combined discount and deterministic discount allocation remain unchanged.
- Effective percentage = base + configured fuel percentage points, maximum 100. Decimal inputs accept up to four fractional digits. Creation defaults to zero. Existing `captains.create`/`captains.update` permissions and Captain scope remain authoritative.
- Captain configuration and global base changes share a PostgreSQL transaction-level advisory lock, so concurrent configuration writes cannot each validate against an obsolete counterpart. Global base increases exceeding any configured Captain's remaining share are rejected. Allocation also validates against its actual base snapshot, which may differ from the current global setting.

## Exact money and history

The final entitlement uses the existing BigDecimal/currency `HALF_UP` rounding once, on the combined percentage. Fuel money is the difference between this final rounded entitlement and the independently rounded original base entitlement. This attributes the rounding residual without creating a second minor unit: at a fee of 0.01, base 50%, fuel 50%, final entitlement remains 0.01 (not 0.02).

For a fee of 10,000: base 50%, fuel 10% produces base 5,000, fuel 1,000, final Captain 6,000, company 4,000. Customer delivery remains 10,000. Base 70% plus fuel 10% produces 8,000 / 2,000.

The pricing header retains the original base percentage. New allocation rows snapshot base, fuel and effective percentages, base entitlement, fuel money, final entitlement, discount, company collectible and company-funded shortfall. The forward migration leaves historical amounts untouched; legacy rows are identifiable by a NULL base snapshot and zero fuel. New snapshot metadata and allocation amounts are protected against mutation, and PostgreSQL verifies the monetary formulas using its exact numeric rounding.

Profile changes never rewrite existing pricing. An explicit later mutable pricing version uses the configuration then in effect. Existing pricing freeze/issued-invoice guards still apply. An issued invoice's immutable financial source snapshot captures the resulting entitlement; posting reads this source rather than current Captain configuration.

V1/V2/V3 posting services and event types are unchanged. V3 CASH adjusts only the existing company share/shortfall calculation. SHAM_CASH and V2 obligations consume the same authoritative entitlement. Fuel is not a cash receipt, payout, expense or product reimbursement. Physical collection, custody, customer credit and return principal remain distinct. Discounts can still create the established company-funded shortfall; no negative company collectible is introduced.

## UI and activation

- Captain create/edit and profile: `نسبة إضافية للكابتن (%)` / Additional Captain share (%), following the owner's clarification that the increase need not be fuel-specific. The internal `fuel_allowance_percentage` name is retained; the accounting meaning is additional percentage points.
- Authorized Order delivery-pricing details: historical base, applied fuel, final Captain percentage, and company percentage before discount. Amounts remain in the pricing currency. No latest-profile value is used to explain historical allocations.
- New forward migration: `2026_10_17_000000_add_captain_fuel_allowance.php`. It adds columns/checks and one allocation trigger; it does not replace financial posting functions, introduce events or rewrite ledger history. Rollback is intentionally forward-only.
- No dependencies, new permissions or seeding are required. Owner review and a separately authorized migration are required before deploying this code. No owner database, `.env`, Toshiba installation or release archive is changed here.
- Focused automated evidence and residual browser limitations are reported with the implementation. The complete regression suite is not represented as rerun.

## Files in this change

- `app/Http/Requests/CaptainRequest.php`: input validation and localized errors.
- `app/Models/Captain.php`: exact decimal configuration attribute.
- `app/Models/OrderDeliveryAllocation.php`: historical percentage and money attributes.
- `app/Services/CaptainFuelAllowanceService.php`: exact percentage validation and shared configuration lock.
- `app/Services/CaptainService.php`: authorized, scoped configuration persistence.
- `app/Services/DeliveryAllocationService.php`: canonical effective share and rounded attribution.
- `app/Services/DeliveryPricingSettings.php`: prevent conflicting base/individual configuration changes.
- `app/Services/OrderDeliveryPricingService.php`: capture participating Captains' configuration; preserve historical-schema behavior.
- `database/migrations/2026_10_17_000000_add_captain_fuel_allowance.php`: additive schema and snapshot guard.
- `lang/ar/captains.php`, `lang/en/captains.php`: matching UI/error labels.
- `resources/views/captains/form.blade.php`, `resources/views/captains/show.blade.php`: configuration field and current value.
- `resources/views/components/captains/delivery-share.blade.php`, `resources/views/orders/_delivery-pricing.blade.php`: applied historical breakdown.
- `tests/Feature/CaptainFuelAllowanceTest.php`: focused calculation, configuration, access, history and posting regressions.
- `tests/Feature/OrderFinancialPostingTest.php`: forward-migration proof with existing Captains and posted V1 money.
- `docs/captain-fuel-allowance.md`: integration, rounding and activation notes.

## Verification

All database test writes used `tests/Support/run_isolated_pgsql.php`, which verified `current_database() = abshir_testing` before creating disposable schemas. Historical V1 fixtures created their own nested disposable schemas. No owner database was migrated or mutated.

The initial focused selection covered `CaptainFuelAllowanceTest`, `DeliveryPricingTest`, `CaptainManagementTest`, `OrderAccountingV3Test`, `OrderAccountingV2Test`, `OrderFinancialPostingTest`, `SharedOrderFinancialPostingTest`, `MixedCustomerCreditTest`, `LaunchCustomerCompensationTest`, `CustomerCreditCashPayoutTest`, and `InvoiceCorrectionHardeningMigrationTest`: 238 cases, 191 passed, 47 errors, 1,594 assertions. Errors were isolated to 39 historical V1 cases reading the not-yet-existing configuration column and 8 new-feature fixture cases. The fixture issues were an invalid direct User-permission relationship and purchase timestamps after the PostgreSQL outer test transaction's fixed `now()`. No financial guard was weakened. Historical pricing now retains its original return shape until the additive schema exists; new fixtures reuse the established past, whole-second clock convention.

Only the affected groups were rerun after corrections, with fresh isolated schemas so the latest migration SQL actually executed:

| Final targeted run | Passed | Failures/errors | Skipped | Assertions |
| --- | ---: | ---: | ---: | ---: |
| `CaptainFuelAllowanceTest` (including the added NULL-input case) | 33 | 0 | 0 | 197 |
| Existing `OrderFinancialPostingTest` V1 regressions | 39 | 0 | 0 | 193 |
| New `test_fuel_migration_preserves_existing_captains_and_posted_v1_money` | 1 | 0 | 0 | 8 |

The remaining 167 cases passed in the initial selection and were not repeated. Thus 240 distinct focused cases were covered across these runs; assertion counts above are per execution, not a summed unique-assertion claim. The complete suite was not run.

PHP syntax, scoped Pint, Blade compilation, translation-key parity and `git diff --check` passed. `npm run build` passed with a non-blocking pre-existing CSS type-hint warning. No dependency files changed. Actual browser inspection of this new configuration was not performed; owner review, separately approved migration activation and UI acceptance remain necessary before deployment. No release archive or Toshiba installation was changed.
