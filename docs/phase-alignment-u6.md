# Original milestones and U6 alignment

Baseline: `livewire-ux`, `a983413`. Original numbered milestones remain authoritative; U/MC labels describe follow-up implementation batches, not replacement phases or renamed history.

| Original milestone | Existing implementation / evidence | Remaining scope |
| --- | --- | --- |
| Phase 7 — delivery pricing/discounts | Existing audited pricing/classification/override services; mutable repricing follow-up; MC shared delivery units and one combined discount | Consolidated browser acceptance; no pricing-policy change in U6 |
| Phase 8 / 8.5 — Invoice and Order operations | Invoice snapshots/Collection, exceptions/evidence/ratings; Wizard/Workflow, B2/B3 and U3 presentation | Intermittent browser draft loss remains unproven fixed; integrated browser acceptance |
| Phase 9A–9C — ledger/financial operations | Immutable ledger, attributed branch sources, returns/corrections/recoveries; MC1–MC4 shared accounting; `a983413` shared bills and uncollected Invoice versions | Shared actual-payer returns and collected corrections remain blocked; no settlement UI is required by the owner's simplified policy |
| Phase 9.5A | `e170bc9` localization/RTL foundation | Preserve, do not restart |
| Phase 9.5B.1–B.3 | `b1766b2`, `f09b5dc`, `c507fa4`, `39dce60` Orders/customers/areas, Captains/Shops, catalog/pricing and address-key fix | Later U3/U4A/U4B visual additions still require integrated UAT |
| Phase 9.5C | `41e65dc` financial localization | Financial screen visual uniformity/native inputs and browser inspection are not blanket-accepted; U6 updates display dates and explicit action styles only |
| Phase 9.5D | `7e22bbb` Arabic/admin/error/RTL milestone | Owner-reported collapsed navigation, buttons and Syrian date consistency are genuine follow-up gaps; U6 addresses these, without asserting complete visual acceptance |

U1 supplied the scoped maryUI/daisyUI application shell; U2 operational dashboard; U3 Wizard/Workflow presentation; U4A Customer/Shop/catalog presentation; U4B Captain/branch/admin presentation; U5 secure Captain read-only information. MC batches extend Phase 8/9 shared-Order contracts rather than introducing new UI milestones. See the existing README and MC documentation for their financial boundaries.

No single complete original roadmap document was found in `docs`; this mapping uses README milestone decisions, Git milestone subjects, the UAT backlog and implementation documents. It does not infer completion of unspecified later original phases.

Focused testing confirmed a pre-existing Phase 9.5C gap in BOTH financial catalogs: `events.SHARED_ORDER_PRODUCT_REIMBURSEMENT_DUE`, `events.SHARED_ORDER_DELIVERY_ENTITLEMENT_DUE`, and `events.SHARED_INVOICE_CASH_CUSTODY_RECEIVED` are absent. `FinancialLocalizationTest::test_financial_catalogs_match_and_every_event_has_a_distinct_display_label` fails on the first missing key. The enum, financial catalogs and that test are unchanged by U6. Counterpart-key parity alone cannot detect a label absent from both languages. This remains an IMPORTANT separate localization follow-up; U6 is explicitly limited to sidebar/buttons/dates/shell, so it does not add unrelated financial translations or claim Phase 9.5C fully complete.

## U6 presentation inventory

- Shell: remove native `details open`; native summary click/Enter/Space remains authoritative, Alpine mirrors `aria-expanded`, `wire:ignore.self` protects manual native state during unrelated morphs. New page navigation/refresh starts collapsed. Active route remains marked and its closed parent is highlighted.
- Desktop: header brand hidden while the permanent sidebar brand is visible, restored when sidebar is collapsed. Mobile brand/drawer retained. Sidebar's intentional independent scrolling retained; source inspection found no main-content fixed-height nested scroller requiring removal. No Dashboard screenshot was attached to this request, so screenshot-specific defects cannot be confirmed.
- Buttons: existing primary/secondary/danger components and explicitly opted-in legacy actions share geometry/focus/disabled styles, preserving semantic colors, types, names, links and Livewire bindings. Existing `.mary-surface .btn` isolation remains; no global button selector. Invoice print has its own self-contained print-hidden control style.
- Date display: `UiDate` distinguishes date-only from instant values. Instants display in Asia/Damascus; calendar dates never convert timezone. Numeric output `dd/mm/YYYY` (optional 24-hour time/seconds) is isolated LTR; long output uses Syrian month names and Western digits. No persisted timestamp, query boundary, snapshot content or ISO input value changes.
- Dashboard and Captain charts format ISO day labels only at the view boundary. Original query snapshots/metrics remain unchanged. Air Datepicker retains `yyyy-MM-dd`, local numeric-part parsing, reactive bindings, clearing and query semantics; Syrian locale corrects months and weekday spelling. No new dependency.
- Affected page families: Dashboard, Orders index/Wizard-shared controls/Workflow/history, invoices and print/collections, Captain information/employment/assignments/groups/financial histories, Shop partnerships, Product memberships/pricing history/comparison/estimates, delivery and external bill-fee administration. Customer/User/Role/Permission/branch/catalog forms benefit through existing shared action components/scoped button styles.

## Remaining acceptance

All these families still require consolidated 375px/1440px browser checks: native details keyboard/click/Livewire updates, drawer, collapsed-sidebar branding, wrapping/buttons, Air Datepicker placement and date-only/time controls. Automated markup/formatter/server tests are not visual/browser acceptance. Wizard state loss, full regression, shared refund contracts and destructive demo-reset compatibility are separate backlog items. U6 does not activate migrations 060000/070000 or any financial policy.
