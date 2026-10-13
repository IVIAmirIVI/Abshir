# Phases 14–16 overnight development progress

## Verified baseline

- Branch livewire-ux, committed HEAD 1e16f51 (Phase 13).
- Initial application worktree clean; the three historical untracked documents remain preserved.
- No owner database writes, migration activation, reset, deployment or Git mutations performed.

## Phase 14 — COMPLETE for read-only scope

Dedicated mobile-first read-only Captain Portal, active and historical participation, role/collector visibility, own-Order details, actual Shop milestones/receipt presence and requested item quantities. Existing historical simultaneous-assignment query and financial/performance self-view reused. Current work contact requires current assignment, employment, active current operational Branch and nonfinal Order; historical access remains minimal and private. No new permission or operational/financial write action. New portal is linked from Dashboard and existing self-information without replacing original navigation.

## Phase 15 — PARTIAL — tested foundation, activation deferred

Versioned explicit JSON resources for current Captain identity, own active/history Orders and minimal own detail. Existing web session and active-user identity guards, 20-row pagination, per-user rate limiting and private/no-store headers. Default feature flag off; no token issuance/external auth, no private contact/evidence/ledger projection. API activation needs separate owner approval. Targeted recent reports/CSV/notifications/benefit/commission review found no additional justified financial-contract change. Final selection includes financial rollback, shared blocked boundaries, approval idempotency, Shop payment replay, notification outbox recovery and report export authorization/formula protection.

## Phase 16 — COMPLETE as release preparation, not deployment

- Read-only abshir:release-preflight command: exact database/schema identity, pending migration inventory, table counts, validated FK/enabled trigger and named function checks; optional production configuration/private-root checks. No repair or activation.
- Owner database read-only inventory: abshir 3 Orders / 8 pending; abshir_uat 2 / 8; abshir_uat_v2 0 / 7. The seven Phase 10–12 migrations remain unapplied on clean V2 UAT. No new migrations introduced.
- Blank-secret .env.production.example, guarded by a narrow Git-ignore exception.
- docs/release-preparation-runbook.md covers dependencies, secrets, TLS/storage, approved migration order, actual restore validation, process supervision, smoke/incident/recovery limits.
- docs/consolidated-release-uat.md covers the complete system and blocked paths at 375/768/1440px. Browser acceptance and intermittent Wizard draft stability remain unproven.

## Quality and verification checkpoint

PHP syntax, scoped Pint, Blade compile + compiled new-template PHP lint and npm production build PASS. Existing maryCSS typehint warning only; no dependency upgrade. git diff --check PASS. Final accepted focused result: 33 distinct PostgreSQL cases / 489 assertions PASS across initial successes and targeted retries, exclusively through tests/Support/run_isolated_pgsql.php in disposable abshir_testing schemas. No remaining failures in this selection. See docs/release-readiness-summary.md for exact execution history and acceptance classification. No full suite or browser UAT.

## Remaining owner gates

Review uncommitted code/documents, one full isolated regression, separately approved pending migration and permission activation with verified restore, one consolidated browser UAT, approved production configuration/scheduler/queue/private-storage verification and explicit deployment approval. Captain write permissions/external token auth and unsupported shared payout/collected correction/company handover contracts are not silently enabled.
