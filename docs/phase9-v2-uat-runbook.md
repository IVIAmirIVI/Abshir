# Phase 9 — prepared V2 UAT baseline and focused UI implementation

Verified 2026-10-12 on `livewire-ux` / `3e627eeaafafb10b478795735fa49b7bf3ecfdbd`. This is development/UAT preparation, not production acceptance. No Git staging, commit, push or merge occurred.

## Current database state

**Stage B: PASS.** The already-created target `abshir_uat_v2` is prepared and accounting V2 is activated there. Do not repeat schema restore, reference import or activation against this populated database. The application still connects to **abshir_uat**; .env is unchanged.

Target identity: PostgreSQL 18.4, 127.0.0.1:5432, database abshir_uat_v2, schema public, owner/login abshir_app, UTF8/libc, Arabic_Morocco.1252. No additional database or role was created.

Schema source: verified custom archive `abshir_uat_stage_a_20261012_001719_94182748.dump` outside Git, 844740 bytes, SHA-256 `a8f9878bd892d5d00c6672ef6c0a87852b457e1a13f71375ae6d1458c05ecce6`. See [Stage A evidence](phase9-stage-a-restore-verification.md). Schema-only public restore used --no-owner, --no-privileges, --single-transaction, --exit-on-error and returned 0. btree_gist was installed only in the target; restored catalog fingerprints matched the source before import.

Reference source was explicit abshir_uat in one REPEATABLE READ / READ ONLY snapshot. All 36 baseline-allowlisted tables were copied in FK dependency order in one target transaction; complete row digests and retained IDs matched. No source data was modified. Reference sequences were synchronized only in the target.

| Retained definition/master | Count |
| --- | ---: |
| Users / roles / permissions | 4 / 3 / 147 |
| Branches / Areas | 2 / 4 |
| Captains / employment periods | 2 / 2 |
| Customers / phones / addresses | 8 / 8 / 9 |
| Shops / Products / categories / units | 7 / 5 / 6 / 4 |
| Delivery rules / bill fee rules / system settings | 11 / 2 / 2 |
| Financial owners / account definitions | 2 / 6 |

Captains **2 and 3**, associated Users **5 and 7**, and home Branch **2** are retained. Both are eligible for the intended Branch. Employee Order-create/assignment permission, home-Branch access and foreign-Branch denial were checked through application methods; Super Admin retains its bypass.

Only `database/migrations/2026_10_11_185816_add_versioned_order_accounting_contracts.php` was applied, using an explicit process-scoped target connection with current_database() checked immediately beforehand. Exit 0. Registry: **85 migrations, zero pending/unknown**. New version is smallint NOT NULL, default 2; immutable binding and enabled V2 event/set/correction/reversal/payout guards coexist with V1 protections. No historical migration was changed.

All **50 excluded tables remain empty**, including Orders, Invoices, Collections, purchase/bill/return/refund/evidence/snapshot/payout/deposit/recovery history, financial transactions and entries. No test business record was created in the target.

| Captain | CAPTAIN_DEPOSIT | CAPTAIN_COMPANY_RECEIVABLE | CAPTAIN_CASH_CUSTODY |
| --- | ---: | ---: | ---: |
| 2 | 0.0000 | 0.0000 | 0.0000 |
| 3 | 0.0000 | 0.0000 | 0.0000 |

FinancialBalanceQueryService confirmed these globally and separately in both retained Branches (2/3), in SYP. No inferred settlement was used.

Final read-only verification: all 35 non-migration retained table digests still match abshir_uat, all excluded counts zero, **265 FK checks with zero orphans**, no unvalidated constraints/disabled triggers, **78 sequence checks with zero issues**. All public-table fingerprints in **both original databases (86 each)** still match the verified source snapshots. abshir_cleanup_preview was never accessed; restore-verification databases were not changed.

Active SYP fee rule remains fixed 10 below 300, fixed 20 from 300 to below 1000, then **3% of the entire principal, nearest 5 HALF_UP**. Historical 2% rule is preserved. PHP/SQL examples 299/300/1000/1250/1500 agree at 10/20/30/40/45. Captain share remains 50%; all three delivery pricing classes have the intended route. No financial policy was changed.

## Implemented presentation changes

- **UAT-01:** UiDecimal trims only insignificant fractional zeros. Shared money output and relevant Wizard, Workflow, summary and Invoice quantity displays use it. 50.0000 -> 50; 1.2500 -> 1.25. Fractions, huge values, negatives and Western digits remain exact. Float/scientific/invalid values are rejected. Bound inputs, stored precision, calculations, financial postings and snapshots are unchanged.
- **UAT-03:** Wizard inline creation, cancel and delivery exception actions reuse the existing shared maryUI action; disabled appearance and Next loading state are consistent. Actions, type, permission gates and validation remain intact.
- **UAT-06:** Native file selectors use scoped shell/financial/mary styling, RTL logical spacing, width containment, focus and disabled states. Hidden inputs remain hidden. Livewire upload bindings, progress, validation, MIME/limits, private storage and authorized downloads are unchanged.

No dependency, migration, service, permission, lifecycle or financial calculation change was needed. No browser UAT was performed. Desktop/mobile rendering, upload interaction and the previously intermittent Wizard draft loss remain consolidated browser acceptance items.

## Focused verification

All database-writing tests used the official `tests/Support/run_isolated_pgsql.php` runner, explicitly verified abshir_testing and disposable schemas. No full suite or owner-database tests were run.

| Final accepted focused coverage | Tests passed | Assertions | Failed / skipped |
| --- | ---: | ---: | --- |
| OrderAccountingV2Test | 32 | 244 | 0 / 0 |
| OrderWorkflowTest | 42 | 461 | 0 / 0 |
| Real V2 collection concurrency, single + shared | 2 | 22 | 0 / 0 |
| Real V1 migration-state upgrade, all four scenarios | 1 | 61 | 0 / 0 |
| Final UI/Wizard/localization selection | 111 | 4525 | 0 / 0 |
| **Unique focused tests across final accepted runs** | **188** | **5313** | **0 / 0** |

V2 four-scenario assertions use actual production creation/purchase/Invoice/Collection/delivery paths:

| Scenario | One Invoice | Actual collector Custody | Receivable per Captain | Deposit delta |
| --- | ---: | ---: | ---: | ---: |
| Single CASH | 220 | 120 | 60 | 0 |
| Shared CASH | 340 | 240; other 0 | 60 each | 0 each |
| Single SHAM_CASH | 220 | 0 | 160 | 0 |
| Shared SHAM_CASH | 340 | 0 | 110 each | 0 each |

Coverage also verifies exact immutable payer allocation, no product costs in V2 CASH custody, no automatic normal-Order Deposit debit, duplicate/invalid sources, Captain-entry substitution denial, authorization, atomic rollback, immutable history and explicit payout boundaries.

The initial combined run had 188 tests / 5253 assertions: 180 passed and eight existing Wizard display assertions failed because they checked raw HTML with an amount/currency space interrupted by Livewire conditional comment markers. Money rendering was correct. These display-only checks now use assertSeeText; raw stored-amount and financial assertions remain intact. The final changed-coverage rerun is the 111 / 4525 result above; the unaffected 77 / 788 initial passing tests were not needlessly repeated. Totals are unique final coverage, not summed duplicate executions.

JUnit evidence is ignored runtime output: storage/logs/phase9-v2-ui-focused.xml and phase9-v2-ui-final.xml. Disposable schema names were mc_testing_64527cfe1787a6a3 and mc_testing_d817de8e0ba34b41, removed by the official runner.

Other checks: frontend **9 passed, zero failed/skipped**; scoped Pint PASS; PHP syntax PASS; Blade view:cache PASS; Vite npm build PASS; translation structural parity **27 groups PASS** and financial event-label coverage PASS; git diff --check PASS. Build retained an existing vendor Tailwind type-hint warning only. Generated output remains ignored.

## Remaining Phase 9 boundaries and owner decisions

- Normal one/two-Captain V2 execution supports one Invoice, actual full Collection, immutable physical product payers and delivery entitlement. CASH and SHAM_CASH stay distinct.
- Collection does **not** imply Captain-to-company handover or payout. The owner has already chosen internal/off-system settlement with no new normal-Order settlement screen. This decision is preserved; software balances cannot be silently cleared from an unrecorded external settlement. Deposit receipt is not a Cash Custody handover.
- Existing evidenced single-Captain Receivable payout remains available through its original authorized source and attributed limits; shared V2 claims remain excluded by service and SQL guard.
- Collected V2 Invoice correction/refund and unattributed reversal remain blocked. Shared after-purchase cancellation/returns, collected shared corrections/refunds and participation changes after financial binding stay guarded. Reimbursement must never be redirected to a collector who was not the physical payer.
- Shared external bills use the designated collector and their existing separate principal/fee/evidence contract. Company fees remain company revenue, with no duplicated product reimbursement or delivery entitlement.

Only if first-release operation requires these currently blocked corrections/refunds/shared payouts does the owner need to approve the precise attributed financial contract. This task does not invent that policy or add a new phase. Review restricted cases during consolidated UAT; no production readiness claim is made.

## Exact next step and reconnection

Stage B is complete. **Do not migrate or import again.** Separate owner approval is still needed for the local application connection switch.

After approval: stop HTTP/queue/persistent workers; save a private .env backup outside Git; change only DB_DATABASE from abshir_uat to abshir_uat_v2; run php artisan config:clear; verify local / 127.0.0.1:5432 / current_database() = abshir_uat_v2 / public before restarting. Keep APP_KEY and credentials unchanged. Close old Order tabs and sign in again. Then execute the single consolidated authorized browser UAT, including the four-scenario matrix in [Stage B](phase9-stage-b-readiness.md).

Recovery means stopping writers and reconnecting to untouched abshir_uat by restoring only the previous database setting, clearing config and re-verifying identity. Preserve the V2 database; never restore over it, roll back forward-only migrations or convert V2 history to V1.