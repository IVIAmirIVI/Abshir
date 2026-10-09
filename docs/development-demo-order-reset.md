# Development demo Order reset

The command is implemented and validated on `abshir_cleanup_preview`. The first
execution on the real `abshir` database still requires the owner's review of this
preview result. No real-development cleanup has been performed.

## Repeatable owner usage

Stop local queue workers and finish any active browser writes first. From the
Abshir project root, after reviewing/approving the first real run:

```powershell
php artisan abshir:reset-demo-orders
```

The command prints its target and exact deletion counts, automatically creates
and verifies a PostgreSQL archive, and asks you to type exactly:

```text
RESET DEMO ORDERS abshir
```

Any other answer cancels without database changes. There is no `--force`, `--yes`
or noninteractive execution. Run the same command to reset later experimental
Orders; an already-empty database is also safe. This removes **all** Orders in
the development database, not a subset selected by date or a demo flag.

To validate only the isolated preview instead:

```powershell
php artisan abshir:reset-demo-orders --preview
```

Its required confirmation is `RESET DEMO ORDERS abshir_cleanup_preview`. There
is no option accepting arbitrary database names. Neither command connects to
`abshir_testing`.

## Prerequisites and backup locations

- Environment: `local` or `development`; CLI only.
- Normal configured connection: `pgsql`, database `abshir`, schema `public`,
  user `abshir_app`, loopback PostgreSQL host; connection URLs are refused.
- The actual target database and its owner must match the approved identity.
- PostgreSQL 18 executables default to `C:\Program Files\PostgreSQL\18\bin`.
  `ABSHIR_POSTGRES_BIN` may configure the executable directory.
- PHP needs `pdo_pgsql`. On this machine the verified PostgreSQL PHP runtime is:

```powershell
& 'C:\tools\abshir-php\php.exe' -c 'C:\tools\abshir-php\php.ini' artisan abshir:reset-demo-orders
```

No administrator installation, migration, extension, superuser grant or permanent
SQL bypass is needed for the verified owner-controlled database. If function
ownership or privileges differ, the command refuses; do not grant blanket
privileges or disable triggers to work around a refusal.

Archives and reports are stored under:
`storage/app/demo-order-reset-backups/`. This directory is ignored by Git and is
outside serving/public disks. Archives contain sensitive database data: retain
them privately and do not commit or upload them publicly. Nothing auto-deletes
these backups. Verification checks pg_dump success, the custom-format header,
archive size, `pg_restore --list`, the public Orders data entry, and SHA256. It
does not claim to perform a fresh restore on every invocation.

Each `.dump` has `.verification.json` and, for confirmed successful executions,
`.reset-report.json`. A report explicitly records whether the transaction
committed. Restore remains a separate reviewed operation; the reset command
never restores, drops databases, or uses `--clean`/`--create`.

## Maintenance and financial boundaries

1. Verify PostgreSQL identity before writes and again before deletion.
2. Acquire a transaction advisory lock and write-blocking locks on public tables;
   normal readers and pg_dump can still read. A fresh READ COMMITTED view after
   locking avoids missing a writer that committed during lock acquisition.
3. Discover actual FKs and build an exact-row allowlisted manifest. Orders,
   invoices, collections, corrections, returns, evidence references, assignments,
   purchases, bill payments and their exclusively attributable ledger rows are
   included. The additive MC1 purchase allocation table is supported if present;
   the reset does not install its migration.
4. Recognize canonical source IDs and authoritative reversal FKs. A payout is
   included only when every allocation belongs to the targeted data. Unknown
   source types, mixed references, cross-schema/unvalidated FKs, unknown
   dependencies or undeletable cycles cause refusal and full rollback. The
   command never guesses from notes or aggregate balance changes.
5. Export a consistent PostgreSQL backup snapshot while public writers are blocked;
   verify the backup **before** asking for confirmation or deleting anything.
6. Apply a transaction-local DELETE-only exception to the existing PL/pgSQL guard
   definitions. It requires the exact database, backend PID, transaction ID,
   public table and complete original row JSON from the private temporary
   manifest. INSERT/UPDATE and non-targeted rows follow the original guards.
   No trigger is disabled/dropped, no FK is disabled/deferred for deletion, and
   no GUC or permanent SQL function grants a reusable bypass. PostgreSQL's
   transactional DDL keeps these uncommitted function changes invisible to other
   sessions.
7. Delete children before parents with all actual FKs active, including reversal
   and version chains. Drain deferred DELETE checks, restore the exact original
   functions, and compare guard definitions/permissions/activation before COMMIT.
8. Verify zero Orders, all surviving row fingerprints, FK integrity and balances
   equal to the original ledger minus only the targeted movements. Financial
   account/owner definitions are preserved. Independent deposits, withdrawals
   and non-targeted ledger data are never removed to manufacture a zero balance.
9. COMMIT only after verification. An error/disconnect rolls back rows and SQL
   guard definitions together. The command never resets sequences or physically
   deletes attachments. Retained files may become unreferenced.

The command intentionally stops for future unsupported independent source
contracts instead of risking their history. Such a refusal requires a reviewed
extension to the attribution allowlist, not a manual bypass.

## Preview acceptance, 2026-10-09

- Real Artisan preview execution: **19 Orders -> 0**, **25 tables / 264 rows**
  deleted (including 11 financial transactions and 11 entries).
- Deposit: `-322 -> 0`; Cash Custody: `0 -> 0`. No Receivable account was fabricated.
- Exact surviving contents verified across **1,382 tables**; all other restored
  schemas retained; **258 public FKs** checked; guard definitions restored.
- Repeat execution on the empty preview: success, zero additional deleted rows.
- Rollback-only fixtures using real production services preserved two independent
  deposits and a withdrawal, leaving their exact **1,050 SYP** balance.
- Focused preview tests: **11 passed, 43 assertions**. They also cover environment/
  target refusal, backup failure, cancellation, actual independent-connection lock
  contention, unknown source refusal, FK enforcement and rollback of guard changes.
- A read-only comparison of real `abshir` found all **1,382 table fingerprints
  unchanged**, with its **19 Orders** retained. `abshir_testing` was not contacted.
- No MC1 files edited, development migrations run, attachments removed, staging,
  commits or pushes. No full suite or browser UAT.

Run only these opted-in preview tests, using the ordinary development environment
and **without** the standard test bootstrap/configuration:

```powershell
$env:ABSHIR_RESET_PREVIEW_TESTS = '1'
& 'C:\tools\abshir-php\php.exe' -c 'C:\tools\abshir-php\php.ini' -d memory_limit=512M vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap tests/Support/bootstrap_demo_order_reset_preview.php --do-not-cache-result tests/Preview/DemoOrderResetTest.php
Remove-Item Env:ABSHIR_RESET_PREVIEW_TESTS
```

Fixtures roll back on preview; they do not migrate or refresh any database. The
normal PostgreSQL test bootstrap intentionally remains untouched.

## Added implementation files

- `app/Console/Commands/ResetDemoOrders.php`
- `app/Support/DemoOrderReset/OrderResetMaintenance.php`
- `app/Support/DemoOrderReset/PostgresBackup.php`
- `config/demo_order_reset.php`
- `tests/Preview/DemoOrderResetTest.php`
- `tests/Support/bootstrap_demo_order_reset_preview.php`
- This documentation file.

## MC2 dependency extension

The maintenance allowlist now includes `order_captain_selections`. Its foreign keys bind to the same Order and the frozen collecting assignment; PostgreSQL prevents reassignment to another Branch or rewriting audited participant identity. Dependency discovery can therefore remove the Order-owned selection before its assignments, while preserving Captain, Branch and User master rows. The existing exact-row transactional maintenance mechanism discovers the new row-level PL/pgSQL delete guards and restores their original definitions.

No new ledger source type is implemented by the read-only MC2 financial review, so no guessed ledger attribution mapping was added. Unknown future shared financial sources must still stop cleanup until explicitly reviewed. Focused tests cover the new table allowlist and refusal of unattended/testing execution without writes. Destructive reset with MC2 has **not** been executed on `abshir`, `abshir_testing` or `abshir_cleanup_preview` in this batch. The earlier preview results above remain historical results, not evidence of MC2 destructive acceptance.
