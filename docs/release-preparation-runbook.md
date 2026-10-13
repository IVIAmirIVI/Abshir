# Controlled first-release runbook (Phase 16)

Preparation only: no deployment, production accounts, owner migration, data reset or go-live approval was performed. Baseline livewire-ux / 1e16f51; Phase 13 is committed. Preserve existing historical databases and the three earlier untracked documents.

## Read-only database inventory — 2026-10-13

The new `abshir:release-preflight` command checked exact PostgreSQL identities and public schema in read-only transactions. abshir_cleanup_preview was never accessed.

| Database | Orders | Pending migrations | Accounting V2 |
| --- | ---: | ---: | --- |
| abshir | 3 | 8 | Not installed |
| abshir_uat | 2 | 8 | Not installed |
| abshir_uat_v2 | 0 | 7 | Installed |

The first two remain historical V1 sources; do not activate V2 there implicitly. The active clean V2 UAT database still needs the following **existing** migrations, in filename order, after separate owner approval:

1. 2026_10_12_132329_create_shop_commission_drafts_table
2. 2026_10_12_140314_extend_shop_commission_drafts_with_agreement_rules
3. 2026_10_12_144251_create_shop_commission_subledger
4. 2026_10_12_174355_create_captain_benefit_requests_table
5. 2026_10_12_182244_enable_captain_benefit_approvals_and_payouts
6. 2026_10_12_200210_create_security_audits_and_internal_notifications
7. 2026_10_12_210249_create_captain_benefit_notification_events

The additional pending migration in the two V1 sources is 2026_10_11_185816_add_versioned_order_accounting_contracts. This is inventory, not authority to activate them. There are no new migrations from Phases 14–16.

`php artisan abshir:release-preflight --database=abshir_uat_v2 --json` performs identity/schema, pending-file, aggregate-count, FK-validation, trigger-enabled and named prerequisite-function checks only. Missing optional sources have null counts. A nonzero exit with pending migrations is expected. `--production` additionally checks environment/debug/HTTPS/cookies/key/durable sessions/queue/logs/private disks/build manifest. It cannot prove scheduler/worker liveness, complete financial reconciliation, role correctness, TLS certificate validity, backup restorability or browser acceptance. It never authorizes deployment.

## Prerequisites and secure configuration

- Pin the committed Composer/npm lockfiles. PHP 8.5, PostgreSQL 18, Composer, Node/npm compatible with the committed Vite package; verify PHP extensions required by composer.lock, especially PDO/pgsql, mbstring, fileinfo, openssl, tokenizer, XML/DOM, ctype and BCMath/Brick exact-decimal dependencies.
- Serve only `public/`. Deny access to .env, .git, storage, vendor, tests, docs and backup files at the web server. Disable directory listing. Do not deploy UAT secrets or the repository's development .env.
- `.env.production.example` is a blank-secret starting point, not a runnable production environment. Supply APP_KEY, database password and any approved mail credentials from a protected secret store. APP_ENV=production, APP_DEBUG=false, HTTPS APP_URL, UTC persistence and existing Asia/Damascus presentation. Never regenerate an existing APP_KEY: historical encrypted data/sessions depend on it.
- Use a dedicated production database, never any UAT/testing/history database. Restrict network exposure, roles and credentials. Separate the reviewed migration role from the runtime role where infrastructure supports it; do not change financial SQL privileges blindly. PostgreSQL functions/triggers installed by migrations must remain intact.
- DB_SSLMODE=verify-full requires configured trusted CA and a host matching the certificate; it must not be weakened just to make a connection succeed. On a reviewed local socket/TLS-terminated topology document the approved alternative separately.
- Secure HttpOnly SameSite cookies, durable database/cache sessions, restrictive trusted proxy configuration only for the actual proxy network, no wildcard credentialed CORS. Keep CAPTAIN_SESSION_API_ENABLED=false until its separate approval.
- Set LOG_CHANNEL=daily (or protected centrally retained logs), warning level and documented retention. Restrict filesystem/read access; never log passwords/tokens or raw sensitive financial payloads.

## Backup and activation — future owner-approved actions

1. Review the commit, final isolated regression and consolidated UAT. Freeze application writers, queue workers and scheduler during the migration window without killing sessions blindly.
2. Verify host, port, current_database() and current_schema() independently. Save current schema/function/trigger inventory, migration records, per-table counts and exact financial fingerprints outside Git with restricted ACLs. Record SYP effective 3% upper tier / nearest-5 HALF_UP and historical configurations.
3. Use PostgreSQL 18 pg_dump, custom format, unique timestamped filename outside the served directory/Git. Supply connection credentials through protected PG service/password configuration, never command-line passwords or documentation. Capture exit code, nonzero size, SHA-256 and pg_restore --list result. Back up **all private/public attachment directories**, not only the public storage link.
4. Prove actual restore in a **new approved verification database**, matching source locale/provider. Before restore verify current_database() exactly matches the new target. Use --no-owner --no-privileges --single-transaction --exit-on-error; never --clean, --create, --disable-triggers or restore over an existing source. Missing create privileges are a stop, not permission to alter roles. Verify restored migrations, functions, enabled triggers, validated FKs, indexes, sequences, data fingerprints and financial balances. Listing an archive alone is not a restore test.
5. Apply only the reviewed pending paths in the documented order, one command/result at a time: `php artisan migrate --path=database/migrations/<exact-file>.php --force`. First verify the target connection explicitly. Stop on any failure; do not automatically rollback a forward-only financial migration, disable guards or reset a schema.
6. Re-run read-only inventory and verify no pending reviewed paths, enabled guards, unchanged master/financial history and exact account/currency balances. Review the runtime authorization seeder's **diff** separately; do not blindly run DatabaseSeeder (it may create demo data). Any initial account/role setup requires separate owner approval.

## Application build and process management — target only

- Install production packages from locks: `composer install --no-dev --prefer-dist --optimize-autoloader`; `composer check-platform-reqs --no-dev`.
- In the build environment use `npm ci` then `npm run build`; publish the resulting public/build from that same commit. Never serve public/hot in production. Never commit generated assets, node_modules, vendor, logs or backups.
- After target secret/config verification run `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache` (or the reviewed `optimize` equivalent). Rebuild cached config after an approved configuration change. Optimize commands are not migration/activation approval.
- Grant the runtime account narrowly scoped write access to storage and bootstrap/cache; keep code read-only. Create all configured private evidence roots, outside public and every served local root. No storage symlink may point at a private evidence directory. The only approved public link is public/storage → storage/app/public. Test real authorized and unauthorized evidence downloads.
- Supervise `php artisan queue:work database --sleep=3 --tries=3 --backoff=10 --timeout=60`; maintain timeout below configured retry_after. Restart workers on deploy, and review failed jobs without replaying financial business actions blindly.
- Run `php artisan schedule:run` every minute as the runtime account in the application directory (cron/systemd timer or Windows Task Scheduler appropriate to the target). `abshir:deliver-benefit-notifications` is independently scheduled every minute with withoutOverlapping. Monitor its failures/pending outbox and retained logs; enqueue/delivery remains internal, recipient-reauthorized and idempotent. Do not send external notifications in UAT.

## Smoke tests and acceptance gate

After separately approved activation, check health /up, login/logout, all three roles, portal/report access restrictions, relevant migration-dependent empty states, static assets, RTL/Western digits/Damascus dates, private evidence isolation and scheduler/worker execution. /up alone is not proof of PostgreSQL readiness. Use only fictional data until the owner authorizes actual operations. Follow `docs/consolidated-release-uat.md`.

## Recovery and incident response

Disable affected workflows/flag, preserve logs and immutable audit evidence, restrict traffic and stop writers through the managed supervisor. Do not invent reversals, settlements or manual ledger edits. Identify whether the incident is code, configuration or schema/data before recovery.

Code rollback is allowed only if the previous commit is compatible with the current schema and immutable accounting version. Do not run migrate:rollback on forward-only financial history. Restore a verified archive into a **new database**, validate it with matching storage and obtain owner approval before a connection switch. A backup restoration loses post-backup business facts unless separately reconciled; never overwrite the original source to conceal that gap. Preserve original databases/evidence and APP_KEY. Connection rollback requires rebuilding config cache and restarting long-lived workers/local servers; never change the current .env automatically.

## Known blocked contracts — do not unlock

- Shared V2 Order receivable payouts.
- Corrections/refunds of collected V2 customer Invoices.
- Fully attributed company Cash Custody handover recording.
- Shop commission settlement subledger is not company cash/bank GL proof.
- New Captain operational/financial write actions and external token API authentication.

These require specific owner-approved accounting/authorization contracts. No new settlement screens or imaginary cash transfer were added. Final browser UAT (including intermittent Wizard draft preservation) and the full combined regression remain owner acceptance gates, not completed work.
