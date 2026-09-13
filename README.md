# Abshir

Abshir is an internal operations system. It currently includes the project foundation, role and branch authorization, and the Phase 2 customer, phone, address, and area foundation.

## Stack

- Laravel 13, PHP 8.3+
- PostgreSQL (primary application database)
- Blade and Vite with Tailwind CSS
- Laravel Breeze web authentication
- PHPUnit and Laravel Pint

## Local setup

Requirements: PHP 8.3+, Composer, Node.js 20+, npm, and PostgreSQL 15+.

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
npm install
```

Set the `DB_*` values in `.env` for a local PostgreSQL database named `abshir`. The default `.env.example` uses `pgsql` and `Asia/Damascus`; set `APP_TIMEZONE` to the deployment timezone when needed.

```powershell
php artisan migrate
npm run build
php artisan serve
```

## First local user

For local development only, set `LOCAL_ADMIN_NAME`, `LOCAL_ADMIN_EMAIL`, and `LOCAL_ADMIN_PASSWORD` in your untracked `.env`, then run:

```powershell
php artisan db:seed
```

The seeder creates nothing unless both email and password are provided. Do not commit these values or use the seeder for production credentials.

## Quality checks

```powershell
php artisan test
vendor/bin/pint --test
npm run build
```

Tests use an isolated in-memory SQLite database through `phpunit.xml`; SQLite is never the configured primary application database.

## Architecture

Blade is the web presentation layer. Controllers remain thin and must delegate reusable workflow and domain work to focused classes in `app/Actions` or `app/Services` when such work is introduced. Blade templates do not calculate business values. This allows web and future API controllers to use the same application services.

Use Laravel Form Requests for HTTP validation and Policies for server-side authorization as domain resources are added. All timestamps are framework-managed and timezone-aware through `APP_TIMEZONE`. Future financial fields must use PostgreSQL `numeric`/Laravel `decimal` columns, never floating point. Future migrations must use foreign keys, indexes, constraints, timestamps, and explicit nullable rules where appropriate.

### Phase 2 domain decisions

Areas belong to a branch and may reference a parent area in that same branch. The application rejects self-parenting, descendant cycles, inaccessible branches, and cross-branch parents. Inactive areas remain readable on existing addresses but cannot be selected for a new address.

Customers are shared records and are never duplicated per branch. Super administrators can see every customer. Other authorized users can see customers with an address in one of their active assigned branches. An addressless customer remains visible to the user who created it so staff can complete the initial customer workflow safely.

Phone numbers retain the entered display value and also store a centralized digits-only normalized value for lookup and per-customer duplicate prevention. The same normalized number may belong to different customers. Phone and address services serialize primary-record changes in database transactions, automatically select the first record, unset the previous primary when another is selected, and promote a sensible replacement when the primary is removed or deactivated.

### Phase 3 Captain decisions

A Captain profile has exactly one User account and stores only Captain business data. The explicit creation workflow rejects Super Admin and Order Employee accounts, attaches the CAPTAIN role transactionally, and never duplicates login credentials. `users.is_active` controls authentication; the separate Captain operational status (`AVAILABLE`, `UNAVAILABLE`, `ON_LEAVE`, or `SUSPENDED`) is changed manually by users with `captains.status.update` within their accessible branch scope. Every actual status change records the previous and new status, actor, time, and optional reason; suspension requires a reason. There is no work schedule or automatic time-based availability behavior.

Employment history is stored as dated periods. Captain creation opens the first period without asking for an end date, termination closes the current period with its reason and actor while preserving the User and Captain, and rehire opens a new period for the same identity. Termination sets the Captain to `UNAVAILABLE` and deactivates the account; authorized rehire may reactivate it. Periods never overlap and historical branch and group records remain intact.

The home branch is the Captain's permanent base. Temporary branch assignments add operational branches for a bounded or open-ended interval without changing the home branch, and overlapping temporary intervals are rejected. Permanent transfers are the only workflow allowed to change the home branch; each transfer records the old branch, new branch, effective timestamp, reason, and actor in the same historical branch-event table. Active operational branches are resolved from the home branch plus active temporary assignments.

Captain Groups are reusable operational and reporting classifications, including simultaneous memberships. Dated membership rows are retained after they end, reject overlapping membership in the same group, and support membership-at-time queries for later incentive eligibility. Groups do not represent vehicle types. Vehicle metadata is deferred and, if added, will use a separate vehicle model rather than Captain Groups.

### Deferred pricing rule

For a future unknown-shop estimate, take each Shop's latest observed price for the same Product and Unit within the configured recent window (default three days), then suggest the lowest among those latest-per-Shop prices. Break equal-price ties using the more recent observation. The future UI must show the source Shop and observation timestamp, and Order Item snapshots must retain the estimate, source, Shop, observation timestamp, and window used. Phase 3 implements none of this pricing or Order behavior.

## Security baseline

Laravel CSRF middleware, password hashing, guarded model assignment, auth middleware, production-safe exception behavior, and environment-provided database credentials remain enabled. Keep `APP_DEBUG=false` in production.
