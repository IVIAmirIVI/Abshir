# Abshir

Abshir is an internal operations system. It currently includes the project foundation, role and branch authorization, customers and areas, Captains, the catalog and Shop foundation, and Product pricing history and comparison.

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
php artisan storage:link
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

### Phase 4 catalog and Shop decisions

Categories provide hierarchical catalog classification and navigation, with unrestricted depth and cycle prevention. Product Groups are separate, flexible commercial and reporting sets; membership rows retain their joined and left times so future commission or promotion rules can evaluate historical membership without embedding those rules in the Group.

Every reusable Product has a managed default Unit. A Shop Product may use another managed Unit because a Product can be listed per piece, box, or another unit. Product images store public-disk paths rather than database binaries, and the image service maintains one primary image while safely removing stored files.

Currencies are managed records with ISO-style codes and exact decimal-place metadata. Branches may select a default Currency through the forward Phase 4 migration. Shop Product prices use an optional Currency foreign key and PostgreSQL-compatible `numeric(18,4)` storage. A Shop Product is only the Shop's current listed catalog information; it is not price history and updates do not create price observations.

A Shop has an optional Area, and its Branch relevance derives from that Area. This avoids a duplicate direct Branch link while keeping branch-scoped access enforceable. Area-less Shops are reserved for Super Admin management. Mobile visibility and online-order fields are readiness flags. Actual mobile eligibility also requires an active partnership whose corresponding flag is enabled.

Shop Partnerships are implemented as historical relationship periods with active, suspended, or ended status and mobile/online readiness metadata. They contain no commission, balance, settlement, or financial logic. Ending a partnership retains its historical row.

One-time Order items and unknown/one-time Shops remain deferred to the Order phase. Future Order records must support either a registered Product/Shop reference or a preserved one-time snapshot without forcing permanent catalog or Shop creation.

### Phase 5 Product pricing decisions

`ProductPriceHistory` stores append-only observations using exact `numeric(18,4)` prices. Each row identifies its Product, registered Shop, Unit, Currency, centralized source, optional notes and recorder, and both `observed_at` and `created_at`. `observed_at` is when the price was obtained and drives all price selection; `created_at` is only when it was entered. Ordinary application flows provide no edit or delete route. A correction is a new observation with explanatory notes so prior evidence remains queryable.

Comparisons are valid only for the same Product, Unit, and Currency. There is no currency conversion. A known-Shop lookup selects the greatest `observed_at`, breaking equal timestamps by the greatest observation ID. The Shop comparison report applies the same ordering independently to each Shop + Unit + Currency context and never averages currencies or prices.

For an unknown-Shop estimate, the service looks from `reference time - configured window` through the reference time, including both boundaries and excluding future observations. It selects each accessible Shop's latest observation first, then chooses the lowest price among those latest rows. Equal prices use the more recent `observed_at`, then the greater observation ID. The default window is three days and Super Admin can change it from 1 through 365 days. If the window contains no valid same-Product + same-Unit + same-Currency observation, the service returns an explicit unavailable result; it never falls back to an old observation or `ShopProduct.listed_price`.

`ShopProduct.listed_price` remains optional current catalog information and does not create or change price-history rows automatically. Price history also does not update Shop Product listings. Price observations may exist for registered Shops without a Shop Product listing.

The estimate result exposes the price, source Shop and observation, exact observed time, Unit, Currency, configured window, and `LATEST_SHOP_LOWEST_PRICE` method. A future Order Item must copy its estimated unit price, method, source Shop ID, source observation ID, source observed time, window days, Currency ID, and Unit ID into an immutable snapshot. Later price observations must not change that snapshot. For known Shops, future Orders will show the latest same-Product + Shop + Unit + Currency observation as reference only; the customer ultimately pays a separately confirmed actual price. Future actual-price sources such as `SHOP_CONFIRMED`, `PURCHASE_RECEIPT`, and `MANUAL_VERIFIED` belong to Order pricing and are separate from the estimation method.

## Security baseline

Laravel CSRF middleware, password hashing, guarded model assignment, auth middleware, production-safe exception behavior, and environment-provided database credentials remain enabled. Keep `APP_DEBUG=false` in production.
