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
- Simplified MC4 implements shared purchases, one Invoice, actual CASH/SHAM_CASH Collection and unpaid obligations without an additional settlement step. Shared payouts/Invoice handovers are outside owner scope; shared bills/refunds remain unsupported. No abshir activation occurred. See `mc4-shared-financial-integration.md`; historical/single contracts and the optional read-only review remain.
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

## MC2/MC3 consolidated acceptance backlog

- After separately approved schema activation, inspect Wizard optional participant selection, explicit collector, two-unit combined delivery display, exception approval versus pending state, Back/Next and immediate submission at 375px and 1440px. No browser acceptance is claimed by component tests.
- In Workflow, change participants/collector before binding sources with a reason; inspect retained participation history and Damascus/Western-digit dates. Old individual assignment actions must not bypass an audited selection.
- Shared purchase, Invoice, bill execution, handover, payout and shared refunds are not activated. The interface must explain this boundary and must never describe calculated obligations as paid or collected.
- Financial-review GET access requires existing financial/order permissions and Order Branch scope; Captain self-view must remain its authenticated Captain only. Review actual per-Shop physical payer names, one product principal, two delivery units, combined discount once and separate Deposit/Custody/Receivable concepts.
- Before activation: implement and audit shared source/version/hash contracts, actual gross-CASH custody/handover, attributed Receivables/payouts, original-payer returns and paid-correction/recovery handling. Approve the per-Shop odd minor-unit recipient. Preserve all legacy contracts and existing external bill fee rules.
- Demo reset now recognizes the participant selection dependency. Its destructive execution with the new schema has not been rerun on any owner database; isolated future acceptance is still required. No real reset, preview reset or physical attachment deletion was performed in this batch.

## MC4 consolidated acceptance additions

- The owner-approved odd minor-unit policy is final: the lowest numeric immutable assignment ID receives the remainder per Shop. It is not an unresolved owner decision. Check the higher-ID collector case and multiple odd Shop totals in consolidated UAT.
- The earlier MC2/MC3 disabled-accounting list above is historical. Simplified MC4 enables only the tested shared purchase/Invoice/Collection/unpaid-obligation paths after separate schema activation. No actual owner financial event or development migration was performed.
- Compare product principal once, two delivery charges, discount once, frozen actual per-Shop funding, both Invoice Captain identities and collector, actual full CASH Custody versus company-direct SHAM_CASH, separate reimbursements/entitlements, without Deposit netting or any invented handover/payout. Shared claims must not appear in existing payout selectors; unrelated supported payouts remain available.
- Shared delivery must fail when any physical purchase/actual source, pickup or receipt is missing. Business mutations, participant replacement, shared corrections/returns and bill execution remain guarded. Invoice cash handover and company settlement are off-system policy, not a new required software workflow; no imaginary handover has been posted.
- Captain self-view must show only its own balances, one delivered Order/entitlement, separate currencies and historical Branches; scoped employees must not receive global balances. Simulations remain read-only.
- Actual destructive demo-reset acceptance on the activated schema is deferred because the existing safe engine does not accept disposable test schemas. Do not weaken its owner-database identity guards or run it on either owner database as a substitute test.

## Owner simplification supersedes earlier settlement roadmap

- MC5, invoice_cash_handovers and shared per-Order payout workflows are explicitly outside scope. Earlier MC1–MC3 activation notes are historical, not a requirement to build those features. The per-Shop odd-unit policy is approved.
- Normal two-Captain execution must reach one Invoice, actual Collection and DELIVERED without any settlement form. Existing recorded custody/unpaid balances must never be falsely cleared merely because the company settles off-system.
- Consolidated UAT should cover the higher-ID collector, two delivery charges, combined discount once, product principal once and absence of split/payment inputs. Shared bills/refunds/corrections remain explicit exceptions.
