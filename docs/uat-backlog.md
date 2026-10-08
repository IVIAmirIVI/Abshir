# Consolidated UI and operational UAT backlog

Manual browser UAT and the full regression suite are deferred until the integrated design is complete. Component tests do not prove intermittent browser races resolved.

## U5 read-only Captain information

- Inspect the Captain profile information link and dashboard at 375px/tablet/1440px: Arabic RTL, Western digits, Gregorian dates, long amounts, current balance cards, GET period/Branch filters, chart and numeric fallback, keyboard focus and no page overflow.
- Owner-approved self-view uses the `view-own-captain-information` Gate and `/my-captain-information`, resolving the fresh, unique `Captain.user_id` relationship from the authenticated active CAPTAIN User only. No seeder/permission activation is needed. Verify missing/revoked identity, mixed roles, manipulated query IDs and navigation visibility; no administrative permission is granted.
- Check full Deposit, Company Receivable and Cash Custody separately per Currency across historical Branches, including negative Deposit, reversals, inactive Branches and transfers. Branch/period filters must not partition or change these full balances. SUPER_ADMIN sees global accounts; branch-scoped employees see only clearly labeled branch-attributed net entries, with full balances unavailable.
- Delivery entitlement uses current collected/delivered immutable Invoice sources or completed correction allocations, never the customer's delivery charge, product reimbursement, Custody or an assertion of payout. Old history without reliable snapshots is excluded; incentives/deductions remain unavailable rather than fabricated.
- Verify assignment-in-period and participation-at-final-transition with removed/replaced Captains, cancelled Orders, Damascus midnight boundaries and Today/7/30 filters. Administrative Financial and Order sections retain their existing separate permissions and Branch scopes. Self-view includes only the linked Captain's own history across Branches and has no profile-management link or mutation controls.
- No Captain field actions, settlements, multi-Captain accounting, packages, migrations or financial writes were added. Comprehensive browser UAT and full regression suite remain deferred.

## Known limitations

- **Wizard state loss remains unproven fixed.** Reproduce repeated immediate Step 2 Next / Back / forward with pending search and inline creation. Capture real browser request order and compare visible selections with server draft. Do not mark accepted from Livewire component tests alone.
- Wizard draft products currently contain quantities, not estimated/actual prices. U3 displays unavailable totals honestly; recorded Workflow item/Shop totals still use the existing exact-decimal service. Adding draft price entry or estimation is separate work, not a U3 pricing change.
- No two-Captain accounting or Captain field application is added. Existing assignment and financial restrictions remain authoritative.
- U2 financial KPIs remain deferred. Its follow-up covers confirmation, eligible Captain assignment and missing Shop receipts after purchase, not every possible blocker.

## Final browser checkpoints

- **Desktop/mobile:** inspect 1440px and 375px, narrow tablet, RTL stepper, wrapping long names/references, sidebar, action hierarchy, no horizontal overflow, and keyboard focus.
- **Complex controls:** Customer/Shop/Product search, selection during debounce, modal Escape/focus restoration, phone/address creation, file input, and native date/time controls. U3 preserves their existing lifecycle instead of replacing them with new widgets.
- **Wizard:** navigate all four steps and back/forward; validate at the owning step; verify selected Customer/address/Shop/Product and quantity survive re-render and failed submission. One primary Next/Create action; loading disables duplicate submissions.
- **Pricing exceptions:** compare automatic price, requested exception and effective creation price. Pending approval must never appear approved or replace the automatic price prematurely. Verify review, creation, retry, audited approval and context changes.
- **B2 evidence reuse:** one selected image plus explicit dual-purpose attestation must create separate authoritative purchase and receipt records; inspect private downloads, failure cleanup and retry. No inferred pickup or status change.
- **Workflow:** next action agrees with persisted facts; optional contact/ready history is not demanded after later facts. Final/issued restrictions, Captain collection responsibility, Invoice CASH/SHAM_CASH, external bill service, cancellation and corrections remain clear and guarded.
- **Dashboard:** Today/7/30 and branch changes update charts and numeric tables; browser Back/forward restores filter state; empty datasets and chart-loading failure remain honest. Verify Damascus dates and branch/role isolation.
- **CSS isolation:** maryUI/daisyUI scoped styles must not leak into PowerGrid, legacy forms, Select2, financial reports, read-only Order Show or guest/auth pages. Check nested mary-surface styling and Tailwind 3/4 interoperability.

U3 automated validation covers server behavior and rendering only. No browser acceptance is claimed here.

## U4A management presentation

- Review Customer, phone/address, Shop/type, Product/category/unit/group pages together at 375px, tablet and 1440px; verify RTL wrapping, keyboard focus, validation, pagination and long names.
- Confirm Shop master SHOP/RESTAURANT badges stay distinct from optional ShopType metadata and that branch/global visibility remains truthful.
- Retest Customer phone leading zero, mobile/landline validation and duplicate-warning request ordering; the existing Alpine/fetch widget is preserved.
- Native selects, product image upload and membership/partnership datetime controls remain intact; no component rewrite or financial authority was introduced.
- Currency administration, price-history/comparison screens, other catalog association forms and financial operations are deferred to their scoped design batches.
- Responsive behavior is designed and covered by rendering checks; comprehensive browser acceptance and the full suite remain deferred.

## U4B Captain and administration presentation

- Review Captain creation/editing, manual status changes, employment termination/rehire and immutable history navigation; distinguish operational status, account activation and employment.
- Retest temporary assignment, permanent transfer and group membership forms with current/foreign branches and date intervals.
- Review role/branch/permission checkbox selection, validation redisplay and protected system-role disabled controls; no permission or posting semantics changed.
- Verify 375px/tablet/1440px card grids, forms, financial-table scroll containment, keyboard focus, long identifiers and pagination.
- Check scoped mary CSS interoperability with Wizard, Workflow, PowerGrid, Select2 and dashboard charts; no additional Alpine initialization.
- Native date/datetime/select controls and permission checkboxes remain intact. Captain financial operations and financial/pricing settings are deliberately deferred.
- No browser visual acceptance is claimed; final manual UAT and the full regression suite remain deferred.
