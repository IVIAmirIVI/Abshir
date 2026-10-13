# One consolidated system browser UAT — owner acceptance plan

Not executed. Use fictional data after separately approved schema/permission activation in the verified UAT database. Never use abshir_testing for browser data or reset the historical databases. Confirm exact connection and record the reviewed commit first. The full isolated automated suite is a separate owner gate; the prior Phase 13 result (1146 tests / 27883 assertions / one expected skip) is owner-reported history, not a result of this combined task.

Use three separate signed-in accounts: SUPER_ADMIN, branch-scoped ORDER_EMPLOYEE and linked CAPTAIN. Add a foreign Branch and second Captain to test denials, not merely hidden buttons. Repeat key flows at 375px, 768px and 1440px; record PASS/FAIL, actual result, evidence and responsible reviewer. Browser widths and PHP rendering are different forms of evidence.

## 1. Authentication, shell and authorization

- [ ] Login/logout, inactive account denial, password errors, Arabic/English, RTL layout, Western digits, Gregorian/Damascus dates, touch/keyboard focus, narrow cards and table scrolling.
- [ ] Employee only sees assigned active Branches; forged Branch/customer/shop/order IDs fail. Captain cannot open staff reports, customer master pages, approvals, private financial evidence or another Captain's details.
- [ ] Mixed Captain roles and cached relations never change the self identity; unlinked/revoked Captain access fails closed. Original authorized navigation remains available.
- [ ] Private Shop receipts, purchases, refunds, benefit and payout evidence require proper nested ownership and permission; copied/temporary/public storage URLs cannot bypass the endpoint.

## 2. Master configuration and Order Wizard

- [ ] Customers/inline mobile numbers/addresses, current mobile validation and legacy data preservation; hidden-only duplicate phone checks do not disclose existence.
- [ ] Shop business classification, Product/group membership, actual pricing and delivery routes; missing route fails closed. Master changes do not rewrite historical financial facts.
- [ ] Four-step Wizard: Branch/source/customer/phone/address → Shop/product → delivery/reason/evidence exception → Review → one created Order. Test immediate Next after selection/Add, Back/Next five consecutive times, validation errors, unchanged versus changed Branch and harmless rerenders.
- [ ] Explicitly resolve the **intermittent browser draft-loss concern** with actual screenshots/request evidence; automated PHP tests alone cannot clear it.
- [ ] Inline creation and reactive line/Shop totals remain intact; Review and creation use the same authoritative draft. Double-submit/retry creates one Order and one audited exception.

## 3. Operational execution and evidence

- [ ] Single and shared Orders: explicit participants and collector, current Shop facts, purchase evidence/receipt reuse, pickup and legitimate status progression, one normal Workflow. Upload never fabricates purchase/pickup/delivery.
- [ ] Issued Invoice blocks financial/business mutation while allowed observational progress can finish; missing receipt/pickup on any Shop prevents delivery.
- [ ] Cancellation timing and supported returns preserve actual payer identity and immutable history. Verify unavailable exceptions return controlled rejection; do not invent settlement or redirect refunds to the collector.
- [ ] Order Show stays primarily history/summary with working links to the canonical Workflow.

## 4. Four V2 scenarios and independent accounts

For the same known actual product principal and configured delivery/share policy, compare persisted Invoice, Collection, source snapshots and exact ledger entries for:

| Scenario | Expected invariant |
| --- | --- |
| One Captain / CASH | One full customer Collection; only actual Abshir cash component in collector Custody; eligible shortfall/receivable as approved; no automatic Deposit debit |
| Two Captains / CASH | Products once, two delivery charges, one combined discount, one collector, one Invoice; exact persisted purchase split and only company component in collector Custody |
| One Captain / SHAM_CASH | Company-direct Collection; exact product reimbursement and eligible delivery entitlement in Receivable; no Captain cash collection/custody claim |
| Two Captains / SHAM_CASH | One company-direct Collection, immutable per-payer split and individual entitlements; no duplicate principal/Invoice/custody |

- [ ] Deposit, Company Receivable and Cash Custody remain separate per Currency; no combined currencies, inferred settlement, paid receivable or company cash/bank balance.
- [ ] Shared odd-unit product split uses the approved persisted payer rule; higher-ID collector and collector switch before binding preserve actual attribution.
- [ ] Issued historical pricing and V1 records remain fixed; V2 cannot post V1 events. Discounts apply once to the combined charge and do not silently reduce eligible full entitlement.
- [ ] External bill initiation remains optional before principal is known; later principal/evidence selects fixed 10 (<300), fixed 20 (300–<1000), **3% full principal for >=1000, nearest 5 HALF_UP**. Test 299.9999, 300, 999.9999, 1000, 1500; 1500 fee=45. Client preview/server completion/SQL agree; company owns the fee. Missing rule, missing evidence or wrong collector fails closed.

## 5. Commissions, benefits, notifications and audit

- [ ] Authorized commission agreement configuration, specificity/activation/effective dates and frozen Invoice lines; changes do not increase customer Invoice or revise historical earnings.
- [ ] Commission accrues once only after required facts; exact subledger earnings/reversals/payments and outstanding by Shop/Branch/Currency. Payment replay is idempotent; subledger payment does not claim company GL cash/bank posting.
- [ ] Bonus/expense draft, revision, submission, distinct authorized reviewer, creator/self-approval denial, expense evidence, exact accrual and supported source-attributed actual payout. Approval is not payment; product financing is not generic expense reimbursement.
- [ ] Internal inbox only: exact recipient/Branch scope, revoked-access denial, escaped text, one durable notice, scheduled retry/recovery and immutable redacted audits. No external notifications.

## 6. Management reports and CSV

- [ ] Date/Branch/Currency filters retained across summary/details/export; current and historical definitions visible. Invoice ≠ receipt/revenue, obligation ≠ payout, custody ≠ company cash, commissions remain a separate subledger.
- [ ] Shared Order counts once; participation counts separately; totals match source records. Balances include authorized history until period end, not only period movements.
- [ ] Arabic/English UTF-8 Excel CSV, exact decimal strings and timestamps, separate Currency column, pagination versus all filtered exported rows; import monetary/ID columns as Text to avoid Excel auto-rounding.
- [ ] Untrusted formula-like names are neutralized; no private URLs, notes, secrets or cross-Branch rows. Missing optional sources show unavailable, including layout notification counter.

## 7. Captain Portal

- [ ] Dashboard/self-information both link to the new portal. Active cards and pagination show only this Captain's Orders, status, single/shared and current collector/support role.
- [ ] Open/current/in-Branch assignments show required delivery/Shop contact snapshots, product quantities, true milestones and receipt presence only. Support cannot see the collection amount/control.
- [ ] Current collector sees issued Invoice amount only until Collection is recorded; recorded CASH/SHAM_CASH is identified, no new collection button or claim of company handover.
- [ ] Ended assignment, terminated employment, completed/cancelled Order, inactive Branch or transfer out removes operational contact/Shop data but preserves minimal own participation history.
- [ ] Own three financial accounts and permitted movement history remain accessible through the existing self-view; other Captain/branch administrative data and ungranted benefit review/evidence are absent.
- [ ] No new Captain writes, purchase/pickup/delivery buttons, payouts/deposits or private evidence links. Mobile readability at all three widths remains to be accepted.
- [ ] Session JSON interface is disabled (404 after auth). If separately enabled, auth/identity/IDOR/filter/rate-limit/no-private-field checks pass; it is not token/mobile-client authentication.

## 8. Unsupported paths — expected controlled rejection

- [ ] Shared V2 Order receivable payout remains blocked, including forged payout allocations.
- [ ] Collected V2 Invoice correction/refund remains blocked pending its approved accounting contract.
- [ ] No company cash handover is assumed or recorded automatically.
- [ ] No unapproved Captain operational/financial permission or external-token client access.
- [ ] No immutable historical row editing, precision change after use, non-finite money, duplicate companion events or weakening of financial guards.

## Sign-off and remaining release gates

Record only observed outcomes; no production readiness claim from screenshots, empty tables, `/up` or a green automated suite alone. Required before go-live: owner-reviewed code/commit, one full isolated regression, separate pending-migration/permission activation with verified real restore, completed consolidated browser UAT, scheduler/queue/private-storage/TLS smoke checks, approved production environment and explicit final deployment approval. Do not run final demo reset in this assignment.
