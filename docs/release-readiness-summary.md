# Release-readiness summary — Phases 14–16

**Development verdict: READY FOR OWNER REVIEW. This is not production approval.**

Branch livewire-ux; base commit 1e16f51. All changes remain uncommitted. Prior Phase 13 full-suite success is owner-reported; this task did not rerun it.

| Internal milestone | Status | Implemented scope / boundary |
| --- | --- | --- |
| Phase 14 | COMPLETE for read-only scope | Captain Portal, own active/history Orders and scoped work details; existing self-financial/performance view; no new Captain writes |
| Phase 15 | PARTIAL | Targeted safety/performance review and tested versioned session-only JSON foundation; disabled by default. External-client/token contract and activation are deferred |
| Phase 16 | COMPLETE as preparation | Read-only preflight CLI, redacted production example, activation/deployment/restore/recovery runbook and one consolidated UAT plan; no deployment |

## Implemented and tested

- 33 distinct focused PostgreSQL cases / 489 assertions passed across accepted executions; no remaining failure/error/skip in this selection.
- First integrated run: 32 cases, 27 passed, 3 failures + 2 errors. Five new-test failures were resolved without weakening production guards: required collector-change reason, deliberately past purchase fixture timestamp relative to the real PostgreSQL clock, and structured Artisan JSON capture. Five-case retry passed (54 assertions). Production private-root follow-up passed (2 cases / 12 assertions, including one previously passed case).
- Own identity, removed/off-Branch/final privacy, collector attribution, foreign-ID rejection, role revocation, zero new financial writes, constant Shop query count, localization parity, explicit API projections/default disablement/rate limits, read-only preflight/no unauthorized target access and physically private evidence-root checks.
- Relevant existing regressions confirm report export permissions/CSV formula guards, own historical visibility, V2 four financial scenarios, rollback, shared payout/collected correction rejection, immutable approval replay, Shop payment replay and scheduled notification recovery.
- PHP syntax, scoped Pint, Blade cache + new compiled-template lint, translation parity, Vite build and git diff --check PASS. Existing mary CSS typehint warning remains harmless. No package or migration change.

## Implemented, awaiting consolidated browser UAT

The whole system, including the new 375/768/1440px portal presentation, awaits `consolidated-release-uat.md`. Intermittent Wizard draft loss is not cleared by PHP evidence. Responsive class choices, rendered HTML and a successful build do not constitute browser acceptance.

## Pending database/permission activation

Read-only inspections verified public schemas and PostgreSQL 18.4. abshir and abshir_uat retain V1 and eight pending migrations. Clean abshir_uat_v2 retains zero Orders/Invoices/Collections/financial rows and seven pending Phase 10–12 migrations. Installed FK checks passed and triggers remained enabled. See `release-preparation-runbook.md` for exact names/order. Missing sources remain unavailable, not artificial zero totals.

Activation, permission-seeder review and any configuration switch require separate owner approval and verified fresh backup plus real restore. Current .env was not modified.

## Blocked by business/authentication decision

Shared V2 receivable payout, collected V2 Invoice corrections/refunds, fully attributed company Custody handover, new Captain write permissions and external-client/token authentication. Independent Shop settlement is still a subledger, not company cash/bank GL proof. Do not silently unlock or claim settlement.

## Technical blockers / outstanding evidence

No remaining confirmed defect within the implemented combined scope. Technical release evidence still required: one full isolated regression, complete browser UAT, target TLS/cookie/proxy/private-storage checks, actual scheduler/queue liveness, approved production credentials/roles and verified restore on the intended deployment infrastructure. Preflight only inventories configured prerequisites; it does not certify these external facts.

## Exact owner next step

Review this uncommitted checkpoint and the runbooks first. Then run one complete isolated regression via `php tests/Support/run_isolated_pgsql.php`. Separately approve and execute only the seven pending clean V2 UAT migrations and reviewed permission setup after backup/restore verification. Conduct the single consolidated browser UAT, review any actual failures, then approve production deployment independently. Do not deploy or perform demo reset automatically.
