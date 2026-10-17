# Phase 10A — partner Shop commission draft administration

Implemented on livewire-ux from d6d4726. Phase 10 is not complete. No commission calculation, activation, earning, invoice addition, ledger posting or payout is implemented.

## Existing capabilities and approved boundary

ShopPartnership already preserves active/suspended/ended relationship periods and mobile/online flags. Product and ProductGroup are existing catalog identities; ProductGroupMember preserves membership intervals. ShopProduct listings and ProductPriceHistory are pricing metadata, not commission agreements. The repository previously documented commissions as deferred; no approved calculation or accounting contract was found.

The owner confirmed that no other approved commission specification exists and authorized the non-financial foundation while the decisions below are documented. No fixed fee, percentage, precedence, earning instant, refund treatment, account movement or settlement policy is chosen here.

## Delivered administration

The existing Shop partnership history links to its nested commission-draft page, using ShopPartnershipController and the current management components. Each draft identifies exactly one Product or ProductGroup, the existing partnership period, an optional reference Currency and proposed terms for owner review. Reference Currency is not a fee, precision change or settlement instruction. No default rate or monetary input exists.

Latest revisions are listed by default; historical revisions, Product/Group filter and target-name search are available with database pagination (20 per page). Revisions append a new row, keep the original target/Currency identity and name/code snapshots, and record the acting User and an explicit-offset timestamp. The old terms remain readable. Proposed terms are escaped plain text, not executable pricing instructions.

PostgreSQL checks exactly one target, positive/coherent version chain, valid FKs and Currency snapshot presence. Unique initial scope (partnership + target + optional Currency), configuration/version and replacement-row indexes prevent duplicate roots and forks. A dedicated trigger rejects UPDATE, DELETE and TRUNCATE on this new draft table and validates revision identity. Existing financial tables/functions/triggers are untouched. The migration refuses down() to preserve draft history.

Service writes lock Shop then Partnership and recheck access; stale revision/retry and duplicate initial configuration return controlled validation errors. Revision IDs are resolved only after authorization and schema availability checks. Cross-Shop/Partnership/Draft tampering fails server-side.

Authorization reuses shops.view and shop_partnerships.view, plus shop_partnerships.create for initial drafts or shop_partnerships.update for revisions. Initial target selection requires products.view or product_groups.view; optional Currency selection requires currencies.view. No role gains new permissions. Branch access derives from Shop Area; Area-less Shops remain Super Admin only. Existing ended partnership history may be documented without making it effective. New targets/reference Currencies must be active; later deactivation never rewrites the preserved draft.

## Database activation boundary

Only the new file database/migrations/2026_10_12_132329_create_shop_commission_drafts_table.php is added. It may execute during isolated tests only, through tests/Support/run_isolated_pgsql.php. No owner database migration is authorized by this implementation.

On an owner database without that migration, the nested page displays a clear unavailable message and both initial/revision POST actions reject safely; they never auto-migrate. Existing Shop and partnership pages remain available. Deployment/activation requires separate owner approval and the existing backup/identity/path-specific migration procedure. Do not run unrestricted migrate.

## Decisions required before actual commissions

1. **Calculation:** percentage, fixed fee, or another model? On which basis: actual purchase price, confirmed customer product subtotal, quantities, or another exact amount? Are caps/minimums or tax included?
2. **Target resolution:** Product versus Group priority; what happens when multiple Groups cover one Product, or when target/Currency configurations overlap? No precedence or fallback is currently selected.
3. **Time and history:** which instant determines partnership eligibility, Group membership and applicable terms? Which event earns commission, and when should amounts/rules be frozen?
4. **Currency/rounding:** commission Currency, permitted conversions and exact approved rounding. Current Currency decimal_places and unrelated SYP external bill-payment 3%/nearest-5 policy remain unchanged; that policy is not a commission policy.
5. **Cancellation/refund/correction:** which party owes what after return, partial return or collected-Invoice correction, and which immutable source authorizes each adjustment?
6. **Accounting/settlement:** debtor and creditor identities, account kinds, source/event/version contract, idempotency and actual evidence/timing for settlement/payout. No settlement is inferred from delivery, collection or a saved draft.

Until those decisions are approved, a draft cannot become an effective commission rule. It is not an authoritative Order/Invoice/financial source. Future implementation must preserve historical V1/V2 contracts and explicit snapshots, and must never silently increase confirmed customer prices.

## Verification and remaining acceptance

Focused coverage exercises Product/Group draft creation, immutable revisions, duplicate/stale rejection, unsupported financial-field rejection, permission and Branch boundaries, nested-ID tampering, SQL integrity and unavailable schema. A production-service shared V2 purchase/issued Invoice/CASH Collection fixture verifies complete rows across nine invoice/pricing/snapshot/ledger tables remain unchanged after draft creation/revision. All fixture writes occur only in disposable abshir_testing schemas.

Browser UAT is deferred. Actual fee calculation, effective rules, commission financial snapshots, refunds and posting/settlement remain unimplemented pending the owner decisions. No Phase 11 work is started.

### Accepted focused results — 2026-10-12

| Coverage | Passed | Assertions | Failures / errors / skipped |
| --- | ---: | ---: | --- |
| ShopCommissionDraftTest, final acceptance | 17 | 115 | 0 / 0 / 0 |
| CatalogPricingLocalizationTest, final focused run | 5 | 422 | 0 / 0 / 0 |
| CatalogManagementTest, initial unaffected run | 6 | 46 | 0 / 0 / 0 |
| ShopManagementTest, initial unaffected run | 5 | 43 | 0 / 0 / 0 |
| Unique final accepted coverage across runs | 33 | 626 | 0 / 0 / 0 |

Initial development runs exposed a nonexistent Shop factory, an unsupported validation-rule name and old display expectations from before UAT-01. The fixture now follows ShopManagementTest, mutually exclusive targets use the supported prohibits rule, and only visible price assertions were aligned to compact formatting. Raw stored-price assertions remain exact and unchanged. The final acceptance rerun adds read-only write-denial, inactive-Currency/history and direct SQL target checks; unchanged passing groups were not repeatedly run.

All invocations used php tests/Support/run_isolated_pgsql.php with --filter and ignored JUnit outputs under storage/logs. Current-database identity was verified before each generated schema; no owner database test or full regression suite was run. PHP syntax, scoped Pint, Blade compilation, npm build, translation parity (26 commission keys) and git diff --check passed. The existing vendor Tailwind type-hint warning remains non-fatal. No dependency or .env change occurred; the three protected untracked Phase 9 documents retain their original hashes. Git HEAD remains d6d4726, with all implementation changes uncommitted.
