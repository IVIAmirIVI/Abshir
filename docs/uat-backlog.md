# Consolidated UI and operational UAT backlog

Manual browser UAT and the full regression suite are deferred until the integrated design is complete. Component tests do not prove intermittent browser races resolved.

## Release gate R2 — authorization retest

- Two confirmed cross-Branch mutation gaps were fixed in their production services: editing a hidden address of a shared Customer by moving it into an accessible Area, and delegated prepare/abandon/refund/finalize permissions acting on another Branch's Invoice correction. The new regression covers HTTP and direct services, unchanged historical rows/evidence on denial, same-Branch success and Super Admin access. See `release-gate-r2.md` for results.
- In the single consolidated UAT session, verify these denials and the legitimate same-Branch paths with restricted roles. No default role permission or financial policy changed; unsupported shared returns/collected corrections remain blocked.
- The intermittent Wizard browser draft-loss item below is still open. R2 server tests and source inspection do not close browser acceptance or establish production readiness.

## Wizard reliability investigation — after final shared-schema activation

- **CONFIRMED AND FIXED — pending registered Shop after a genuine Branch change:** select a Shop without adding it, change Branch, then advance back to Shops. The old `shopId` reached `knownShopClassification()` / `accessibleShop()` in rendering and produced HTTP 404. The Branch update now clears only the pending registered Shop reference/search/classification and old Branch Area references. Added groups remain present for explicit validation/removal; Customer, phone, source, notes, product editor and one-time Shop text remain intact. A regression failed with 404 before the fix and passes afterward.
- **CONFIRMED AND FIXED — update-hook validation visibility:** reach Review, then receive an invalid Step 1 field update without a navigation action (the regression uses a forged `MOBILE_APP` source). `validateOnly()` raised an error while the viewed step stayed 4. Contact update validation now moves the view to Step 1 and rethrows the original validation error, preserving groups, contacts, notes and the reviewed delivery data. The regression failed with step 4 before the fix. It does not claim that a normal browser sends this forged value.
- **CONFIRMED AND FIXED — inline Area consistency during Branch invalidation:** an already open Shop/address creation modal displays the new Branch's sole Area without a selector. Its draft Area must therefore be updated to that sole option, rather than retaining an old Branch ID or an uneditable empty value. A lifecycle regression proves both forms retain their text and create through the existing services in the correct Branch. Multiple/no-Area cases still require an explicit valid selection; no stored development data is changed.
- **NOT REPRODUCED — intermittent browser draft reset:** no real-browser reproduction was established in this investigation. Five repeated bundled Back/Next sequences, unchanged Branch updates, immediate Add/Next, multiple Shop quantities, harmless refreshes and existing inline-creation/override/shared-Captain lifecycle regressions exercise server behavior, not actual network/DOM races. No browser pass count or end-to-end browser acceptance is claimed.
- **INTENTIONAL BEHAVIOR:** a genuine Branch change returns to Step 1, invalidates participants/collector and invalid Branch-specific selections, and retains added groups so the operator can remove or replace out-of-scope Shops explicitly. An unchanged Branch must not clear pending inputs. Page reload starts a new ephemeral Wizard; persistence across browser refresh is not promised. Search text is not an authoritative Customer/Shop/Product selection. A pending item is committed only by Add. Draft quantities do not imply confirmed purchase prices.
- **REQUIRES BROWSER VERIFICATION:** in consolidated UAT at 375px/1440px, select Customer/phone/address, immediately advance, select and add Shop/Product, immediately advance through Review, then Back/Next repeatedly with pending searches and inline creation. Capture Livewire action/update order and snapshots with entity IDs only; compare rendered controls with the authoritative draft. Also exercise unchanged Branch, genuinely changed Branch and one/two Captains with explicit collector and approved/pending overrides. No diagnostic logging, sleeps, persistence layer, JS request coordinator or dependency was added.
- **STILL BLOCKED — closing the intermittent browser concern:** it stays open until minimal real-browser evidence proves its cause or repeated browser verification supports acceptance. Server tests alone must not mark it resolved. No financial policy, permission, lifecycle, Invoice/ledger semantics or migration was changed by this repair.

Installed Livewire 4 request handling already flushes component debounces for `wire:click`/`wire:submit` and defers non-async actions behind an active same-component request; these Wizard actions do not opt into async requests. This is implementation inspection, not proof of browser stability. The current Wizard uses direct Livewire models and Alpine search controls; the legacy Select2 initializer only matches `data-order-create-select2`, which this Wizard does not have. Step keys replace the viewed partial without creating a second authoritative draft or nested Wizard component.

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

## U6 — Phase 9.5D focused follow-up

Original phase mapping and precise gaps: `phase-alignment-u6.md`. Numbered milestones and Git history are preserved.

- **U6-01 — FIXED in implementation/automated rendering:** navigation groups no longer render `open`, including active-route parents. Native summary toggles remain keyboard/click accessible; Alpine mirrors `aria-expanded`, manual open state is protected during unrelated Livewire morphs. New pages/refresh start closed. Actual browser interaction/flicker acceptance remains pending.
- **U6-02 — FIXED shared geometry/automated component contract:** primary/secondary/danger legacy components, explicit financial/Workflow actions, and scoped mary buttons share touch height, spacing, wrapping, focus and disabled presentation. Destructive colors and all submit/navigation bindings stay intact. Invoice print action remains hidden on print. Page-by-page visual inspection is pending, not accepted.
- **U6-03 — FIXED formatter/automated date regression:** display-only `UiDate`, Syrian month/day catalogs and Air Datepicker locale; Gregorian Western-digit numeric dates, Damascus instant display, date-only preservation and ISO inputs/filter state. Representative year/month/leap boundaries are covered. Native browser date/datetime controls still need consolidated platform inspection.
- **U6-04 — FIXED desktop duplicate branding in scoped shell CSS:** topbar brand is hidden with expanded desktop sidebar and restored on collapse; mobile header remains. Responsive rendering/design inspection is not browser acceptance.
- No attached Dashboard screenshot was available for this task. No additional scroll/menu defect is claimed from an unseen screenshot; intentional sidebar scrolling is retained.
- New shared bills/uncollected Invoice version support supersedes older disabled-service notes below only after separate activation of 060000/070000; shared returns/collected corrections stay blocked. U6 applies no migration or fee configuration.
- Preserve the unresolved intermittent Wizard draft-state browser issue, financial/shared exception limits, final mobile/desktop UAT, full regression and safe destructive reset acceptance. No browser UAT was run or marked passed.
- **IMPORTANT original Phase 9.5C gap, outside U6:** three shared financial event labels are absent in BOTH `lang/ar/financial.php` and `lang/en/financial.php`: `SHARED_ORDER_PRODUCT_REIMBURSEMENT_DUE`, `SHARED_ORDER_DELIVERY_ENTITLEMENT_DUE`, `SHARED_INVOICE_CASH_CUSTODY_RECEIVED`. Existing financial localization coverage fails; parity alone still passes because both catalogs lack them. Leave as a separately scoped translation repair, without changing financial identifiers or posting logic.

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

## Phase 9.5C financial presentation review

- Separate Deposit, Receivable (due, not paid) and Custody explanations; no combined balance or combined currencies.
- Labelled keyboard-focusable scrolling tables and bounded, visibly labelled filters; payout/recovery timestamps use UiDate. Loaded authorized Captain names supplement audit IDs.
- Printed totals wrap without clipping amounts. Existing permissions, financial actions and unsupported shared transitions remain unchanged; no settlement workflow added.
- Consolidated visual checks remain required at 375px, 768px and 1440px: tables, amount forms, long Arabic references, refunds/returns, Captain self-view and print/PDF. Markup checks are not browser acceptance.
- Preserve the intermittent Wizard browser state-loss concern for consolidated UAT; this presentation batch does not resolve it.

## Phase 9.5D administration presentation

- Administration forms now associate field errors with controls, identify required fields without changing validation, and link to their existing parent lists.
- Delivery settings display failed submissions and retain employee-limit input. Fee configuration, delivery rules and approvals reuse the existing management scope; delivery-rule filters have visible labels.
- Unknown HTML conflict exception messages are not displayed. Existing translated evidence-integrity explanations remain visible; HTTP status and financial protections are unchanged.
- Consolidated browser checks remain required at 375px/768px/1440px for labels/errors, checkbox groups, wrapped pagination, fee forms, approval buttons, existing PowerGrid sorting/filtering, modal/drawer keyboard navigation and long Arabic content. No browser acceptance claimed.
- Intermittent Wizard state loss remains in the final browser backlog. Shared actual-payer returns/collected corrections and destructive demo-reset acceptance remain separate limitations, not new administration features.

## Phase 9 clean V2 baseline — current consolidated acceptance

The current [V2 UAT runbook](phase9-v2-uat-runbook.md) supersedes historical financial/accounting and disabled-service notes above. Stage B prepared abshir_uat_v2 with 85 migrations, preserved Captains 2/3 and zero balances. The application still uses abshir_uat; a separate owner-approved connection switch must precede browser financial writes. Do not repeat target import or migration.

- **UAT-01 implemented / automated PASS:** money and relevant quantity displays trim only insignificant zeros (50.0000 -> 50, 1.2500 -> 1.25); exact inputs, stored values and financial authority remain unchanged. Inspect summary, Wizard, Workflow and Invoice print at 375px/768px/1440px, including genuine fractions, negatives and long values.
- **UAT-03 implemented / automated PASS:** existing shared Wizard actions now cover inline creation, cancellation, delivery exception actions, disabled states and Next loading. Verify keyboard/touch targets, immediate Back/Next, validation and double-submit without changing the authoritative draft. The intermittent browser draft-loss concern remains unaccepted.
- **UAT-06 implemented / build PASS:** scoped native file-control appearance preserves hidden inputs, Livewire progress, errors, MIME/limits and private authorized downloads. Verify actual mobile/desktop upload, long filename wrapping, disabled/focus states and existing evidence reuse; no visual/browser acceptance is inferred from CSS or PHP tests.
- Verify the four persisted V2 financial scenarios and version binding after connection approval; no normal Order Deposit debit, no product costs in CASH Custody, no imaginary handover/payout, company-direct SHAM_CASH, immutable physical payer attribution and permission isolation.
- Owner-approved off-system settlement remains outside normal Order UI. Existing supported single-Captain payouts stay distinct; shared payouts and collected V2 corrections/refunds remain guarded. A future attributed policy is needed only if the owner requires these blocked cases for first-release operation. Do not invent a settlement or refund workflow during UAT.

No comprehensive browser UAT or full-suite rerun occurred in this implementation. Consolidated acceptance remains pending; no production readiness claim is made.
