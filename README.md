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

### Phase 6 Order decisions

An Order belongs to one Branch and contains one or more Order Shops, each containing Order Items. The Order has one operational status for the entire workflow; Shops do not have separate status machines. Pricing status is derived separately from item-level actual-price confirmation.

Order creation records immutable Customer, selected phone, address, and Area snapshots while retaining foreign keys for traceability. Registered Shops and catalog Products retain references plus snapshots. One-time Shops and one-time Items exist only within the Order and never create permanent Shop or Product records.

Estimated item prices are copied evidence. A registered Shop uses the latest matching Phase 5 observation; a one-time Shop uses the configured latest-per-Shop lowest-price estimate. Manual estimates are marked explicitly and invent no source. Actual price, Currency, source, actor, and confirmation time are stored separately and never overwrite estimate evidence.

Captain assignments are historical. Active assignments contain exactly one `PRIMARY_COLLECTION` Captain before Support Captains may be added. Multiple Captains work on the same shared Order. Switching the primary Captain is an explicit, reasoned workflow that closes prior assignment rows. Captains cannot administer Orders or change items; authorized Order Employees record quantity changes and replacements while preserving original quantities and change history.

All active Order Captain mutations serialize through a lock on the parent Order row before inspecting or locking assignment rows. PostgreSQL partial unique indexes separately prevent multiple active primary Captains and duplicate active Captain assignments. Direct removal of a primary Captain is rejected while Support Captains remain; the primary must be switched explicitly first.

Customer payments, invoices, collection and cash custody, financial ledgers, commissions, incentives, salaries, returns, messaging, mobile APIs, and the Captain Portal remain deferred.

### Phase 7 delivery pricing decisions

Delivery pricing uses directional, exact origin-Area to destination-Area rules in the Branch default Currency. Each rule also targets exactly one Order Pricing Class: `SINGLE_RESTAURANT`, `SINGLE_SHOP`, or `MULTI_SHOP`. Rules are effective-dated and historical versions remain available for Orders whose `ordered_at` falls in the prior period. Missing routes or class-specific rules return an explicit unavailable result; the application does not fall back to another class or infer a parent, reverse, nearby, or same-Area rule. New Orders retain the selected destination Area reference in addition to their immutable address snapshots, while historical Orders are not assigned a fabricated destination.

Shop Types may explicitly classify their Shops as `RESTAURANT` or `SHOP`. Adding a registered Shop copies that metadata to the Order Shop; an employee supplies it when legacy Shop metadata is unconfigured and always supplies it for a one-time Shop. One snapshotted Restaurant produces `SINGLE_RESTAURANT`, one snapshotted Shop produces `SINGLE_SHOP`, and two or more Order Shops produce `MULTI_SHOP`. The resulting class is stored on the Order, so later catalog changes cannot rewrite historical meaning. A permitted shop-composition change recalculates the class and supersedes current Delivery Pricing, which also makes a READY Invoice fail source revalidation until delivery pricing and the Invoice are refreshed.

Each active Order Captain represents one complete delivery unit. Final pricing therefore requires at least one active Captain and a configured Captain share percentage. Every final price is a versioned snapshot containing the applicable rule, Areas, Currency, suggested and applied unit fees, active Captain count, Captain share, gross total, discount, customer total, actor, and time. Captain assignment count changes reprice while holding the Phase 6 Order lock; a primary-role switch with the same active Captains does not create a price version.

An applied fee that differs from the suggested fee is an override, not a discount. Every override records its reason and comparison, including direct application within the configured threshold or by Super Admin. Larger employee overrides require a single explicit approval or rejection by an authorized user, and approval revalidates the applicable suggestion before creating a new pricing snapshot. Resolved requests cannot be resolved again.

Delivery discounts are limited per Order Employee by a configured percentage. The customer total is gross delivery less the discount, while each Captain's entitlement remains the applied unit fee multiplied by the snapshotted Captain share. Discounts are distributed deterministically in Currency minor units across the active Captain assignments; Abshir's collectible share absorbs the discount first and any remaining entitlement gap is retained as `captain_shortfall`. These records state earned and collectible amounts only. Phase 9 will own all ledger postings and Captain balance movements.

### Phase 8 Invoice and collection decisions

An Order has at most one current Customer Invoice and may retain multiple historical versions. The focused lifecycle is `DRAFT`, `READY`, `ISSUED`, `VOIDED`, and `REPLACED`. A DRAFT becomes READY only through the Invoice service after all Order Item actual prices are confirmed, every actual-price evidence field exists, one Currency is used by Items and current Delivery Pricing, and an active `PRIMARY_COLLECTION` Captain exists. The READY snapshot records the source Delivery Pricing ID and a deterministic fingerprint of all source Item pricing facts. Issue revalidates those sources under locks and rejects stale snapshots until an authorized refresh rebuilds them.

Invoice Items copy the Order Item description and Unit snapshots, quantity, actual unit price, Currency, and a `HalfUp` line total rounded to the Currency's decimal places. The Invoice stores exact Items subtotal, Customer delivery total, and grand total. Issued Customer-facing values are immutable. Corrections require an explicit Super Admin void or replacement workflow; replacement retains the old Invoice, its actor, time, reason, and link to a new READY version.

Collection is full-payment-only and supports exactly `CASH` and `SHAM_CASH`. The amount and Currency must equal the issued Invoice exactly; partial payment, overpayment, debt, and installments are not modeled. A `CASH` collection means the Customer gave the money to the current active `PRIMARY_COLLECTION` Captain, so the collection snapshots both the Captain and Order Captain assignment. A `SHAM_CASH` collection means the Customer transferred money directly to the company owner's Sham Cash account, so both Captain references remain null and an optional transaction reference may be stored. Later Captain changes never rewrite either collection history. A collected Invoice can be replaced only through the Phase 9C.3A financial correction and customer-refund workflow described below; its original Collection remains immutable.

The owner-only Sham Cash reconciliation page lists direct-to-company collections, supports date filtering, and totals each Currency separately. It does not represent Captain cash custody. The printable Invoice contains Customer-facing charges only and supports manual printing, Save as PDF, and the existing manual WhatsApp process.

Normal Order financial events crystallize only after DELIVERED status, a current ISSUED Invoice, and one successful full InvoiceCollection. The latest of the issued, delivered, and collected timestamps is the event time, retaining PostgreSQL microseconds. The issued Invoice freezes an immutable financial-source snapshot of its Branch, issue time, Currency decimal precision, product subtotal, historical participating Captains, exact equal product-funding shares in Currency minor units, delivery allocations, and PRIMARY_COLLECTION assignment. Participant amounts are stored in validated relational snapshot lines using unrestricted NUMERIC. PostgreSQL computes the versioned source fingerprint from the complete ordered header and line set and rejects a mismatched digest at commit. The corrected ledger events use the method-bearing InvoiceCollection as their source.

For normal `CASH`, Captains funded the products themselves. Product reimbursement and the customer-funded portion of Captain delivery entitlement stay outside the company ledger; internal balancing among Captains is their own arrangement. The historical sum of `company_collectible` decreases the PRIMARY_COLLECTION Captain's operational `CAPTAIN_DEPOSIT` in the source Order Branch. Any `captain_shortfall` increases `CAPTAIN_COMPANY_RECEIVABLE` for its specific Captain in that Branch. Normal Orders never create `CAPTAIN_CASH_CUSTODY`.

For normal `SHAM_CASH`, the company receives the customer payment directly. It owes each participating Captain their exact equal share of the issued Invoice product subtotal and their full historical `captain_entitlement`; both increase `CAPTAIN_COMPANY_RECEIVABLE`. No Captain custody or deposit movement is created for that normal collection. Shop receipts remain private operational evidence, not company-ledger product payments.

Captain operational balances are separate by Captain, Branch, Currency, and account kind. `FinancialAccount` keeps its global owner/Currency/kind identity; a Branch balance is the exact signed sum of its immutable entries joined to `FinancialTransaction.branch_id`. The global account balance equals the sum of Branch balances. Deposit may be negative, and Deposit, Receivable, and Custody are never automatically netted across accounts or Branches. Custody represents actual company cash physically held by a Captain: bill-payment principal enters Custody and leaves it at principal handover, while the bill service fee decreases Deposit in the bill Order Branch.

PostgreSQL binds each published Order financial event type to its canonical source family and the source Order Branch, including direct SQL writes. An Order Branch remains editable before financial binding; an issued Invoice, financial source snapshot, completed bill payment, or posted Order event freezes it. Historical Custody correction recognizes exact reversals through `reversal_of_transaction_id`; unrelated earlier movements and bill-principal handovers do not consume an obsolete normal-Order event, while later unattributed decreases require manual review.

Published Phase 9B events and the Phase 9B.6 `ORDER_CASH_COMPANY_DELIVERY_SHARE_HELD` Custody event retain their original meaning but are legacy only. Run `php artisan financial:correct-order-financial-semantics` for a zero-write dry run; `--apply` verifies historical sources, reverses eligible obsolete entries in their original Branch, and targets the final Deposit-debit semantics. Ambiguous consumed Custody requires manual review. Ordinary `financial:backfill-order-events` reports `LEGACY_CORRECTION_REQUIRED` rather than reversing history. Both commands process Orders in chunks; apply is explicit.

### Phase 9C.1 Captain cash receipts and attributed payouts

A Captain Deposit receipt records real money handed by the Captain to an authorized Abshir employee for one Branch and Currency. Its immutable, evidenced source posts `CAPTAIN_DEPOSIT_RECEIVED`, increasing only that Branch's operational Deposit. Negative Deposit is allowed; receiving money adds the exact amount without a separate debt-clearing or netting event. Normal CASH Order company share was already debited from Deposit when the Order completed, so there is no normal CASH handover in this phase. Existing bill-payment principal custody and handover remain separate and unchanged.

A Captain Receivable payout records real money Abshir paid to the Captain for one Branch and Currency. Its immutable, evidenced source posts `CAPTAIN_RECEIVABLE_PAID`, decreasing only Company Receivable. Each payout has immutable allocations to exact positive `financial_entry_id` values from final CASH shortfall or SHAM_CASH product-reimbursement/delivery-entitlement events. The allocations total the payout exactly, cannot cumulatively exceed an original entry, and cannot cross Captain, Branch, or Currency. Legacy or inconsistent Receivable history is not guessed payable. Deposit and Custody are never netted against a payout. Evidence lives on a separate private disk and is downloadable only after a fresh permission, Branch, and nested source check.

Source facts, ledger event shape, and payout allocation integrity are enforced by forward-only PostgreSQL constraints in addition to application validation. Currency is locked before the global Receivable account for a payout; outstanding source amounts and the Branch balance are reread while that account lock is held. The globally keyed FinancialAccount does not move when a Captain changes Branch. An ordinary reversal of an already paid Receivable source fails closed. Generic reversal of a real Deposit receipt or completed Receivable payout also fails closed in the service and PostgreSQL, preserving the immutable receipt and payout allocations. Corrections of these operations or a paid Receivable source require a future controlled Phase 9C.3 correction/recovery document; Phase 9C.1 does not implement that workflow. Settlement sessions, Deposit withdrawal, and incentives remain deferred.

### Phase 9C.2 after-purchase cancellation and Shop returns

An authoritative OrderShop purchase confirmation records the historical Order Captain who paid the Shop, actual positive amount and Currency, purchase time, and private proof. A pickup marker or legacy receipt alone never becomes an invented purchase; legacy candidates require explicit future resolution. A purchase confirmation is immutable, unique per OrderShop, and bound to its Order and Branch in PostgreSQL.

An AFTER_PURCHASE cancellation first creates an auditable intent under the Order lock. It freezes the exact confirmed purchased-Shop set, cancels pending bill-payment requests, and blocks normal delivery, Invoice issue, collection, further purchases, and bill completion. Current ISSUED or collected Invoices, completed bill payments, and existing Order-derived financial events require a future financial correction workflow; this phase does not reverse them. BEFORE_PURCHASE cancellation remains available only where no purchase, pickup, or receipt evidence exists.

Each frozen purchased Shop needs one evidenced completed return before final cancellation. The physical returning Captain may be another historical Order participant, but the refund recipient is always the original purchasing Order Captain. The normal refund equals the verified purchase amount. A smaller positive refund requires a recorded reason; its exact difference is operational evidence only and creates no Deposit, Receivable, Custody, or expense entry. A missing or zero refund cannot complete a return. PostgreSQL checks source links, Currency precision, finite amounts, evidence completeness, immutable facts, and the final exact return set. Purchase and return proof is stored on a separate private disk with authorized, Branch-scoped, nested downloads.

Final cancellation occurs only after every required return is sealed and conflicting financial state is absent. A correctly cancelled Order creates no successful-order delivery entitlement or company share. Deposit reconciliation, customer refunds, Invoice reversal, payout recovery, and broader financial corrections remain outside Phase 9C.2.

PostgreSQL requires intent-based final cancellation history and `CANCELLED` status to commit together, with the exact frozen return set complete. After intent, new pickup markers and normal Shop receipts are blocked at the database boundary; return evidence remains available. Normal Shop receipt ownership is immutable from creation, and receipts under cancellation intent cannot be deleted. The forward-only hardening migrations reject current receipt/pickup signals outside the frozen purchased-Shop set without repairing history. Historical timestamps cannot reveal a late write whose business timestamp was backdated; prospective ownership and intent guards use relational state rather than those timestamps.

## Security baseline

### Phase 9C.3A collected Invoice correction and customer refund

A collected Invoice and its full Collection remain immutable history. A Branch-authorized Order Employee may request a documented REPRICE or CANCEL_AND_REFUND; only Super Admin can prepare its exact amounts, accept a real CustomerRefund, and finalize it. The original Collection method fixes the refund method: CASH is physically refunded by the historical PRIMARY_COLLECTION Captain on that Collection, while SHAM_CASH is refunded by the company. Refund proof is stored privately, and a refund creates no Captain Custody entry.

The corrected customer obligation is the original Collection minus the separate, exact CustomerRefund. REPRICE creates a new issued Invoice version whose payment is already satisfied by those two real-money facts; a second Collection is forbidden. CANCEL_AND_REFUND records zero corrected obligation in the completed correction because a zero-value Invoice is incompatible with the current issued-Invoice constraints. Physical Shop returns and the cancellation intent remain required before final cancellation. A completed external BillPayment prevents cancellation.

Original Order financial events remain visible. If the Order has already crystallized, eligible unpaid events receive official linked reversals and new events are posted from the immutable corrected snapshot in the same transaction as finalization. A correction completed before delivery posts corrected events only when delivery occurs. Additional customer collection, paid-Captain Receivable recovery, automatic Deposit recovery, future offsets, payout reversal and DepositReceipt reversal are deferred; these conditions fail with a controlled conflict rather than guessing a financial movement. PostgreSQL checks source, Branch, Currency, refund and correction identities and the complete correction FinancialEntry set.

### Phase 9C.3B.1 paid Receivable dispositions

Preparation freezes one immutable disposition for each exact historical Captain/source/concept. With original entitlement `O`, historical payout allocations `P`, and corrected entitlement `C`, PostgreSQL requires `0 <= P <= O`, retires the unpaid remainder `U = O - P`, and creates only the residual liability `R = max(C - P, 0)`. The separate repayment requirement is `K = max(P - C, 0)`. Historical payout documents, allocations, and paid ledger events remain unchanged. For `100/40 -> 70`, completion retires 60 and creates a payable residual of 30; `100/40 -> 120` creates 80, and `100/100 -> 120` creates 20 without a zero retirement event.

Every allocation used for `P` is frozen with its original identity and amount. Coverage consumes allocations in payout time, payout ID, then allocation ID order; any excess slices explain `K` without changing real payments. An absent original concept legitimately uses `O=0` and no invented source entry. Unpaid corrections retain the Phase 9C.3A whole-entitlement contract; corrections with historical payouts use explicit version 2 retirement/residual events. Existing completed version 1 corrections and their later payouts retain their original meaning.

Preparation reserves old sources under Currency/global Account locks. PostgreSQL prevents further allocations to reserved sources, and completion permanently removes them from payout eligibility. Unrelated sources in the same account remain payable; economic balances still include all immutable ledger history. Finalization validates the complete source, allocation, hash, Branch, Currency, and event sets again. Captain transfers cannot relocate these historical facts.

When `K>0`, completion fails with `CAPTAIN_RECOVERY_REQUIRED`. A correction with its required customer refund remains `READY_TO_FINALIZE`, preserving that refund and source reservation; it cannot be abandoned or refunded twice. Physical repayment is deferred to Phase 9C.3B.2: the historical Branch's Order Employee will receive and evidence one exact full CASH repayment, with Super Admin finalization. There is no Deposit debit, future Receivable offset, invented Custody, payout reversal, installment, or waiver in this phase. The three forward-only migrations keep paid corrections inactive until all PostgreSQL guards and historical validation succeed.

Laravel CSRF middleware, password hashing, guarded model assignment, auth middleware, production-safe exception behavior, and environment-provided database credentials remain enabled. Keep `APP_DEBUG=false` in production.

### Phase 9C.3B.2 physical Captain Receivable recovery

An immutable `CaptainReceivableRecovery` records one full CASH repayment per correction, historical Captain, Branch and Currency. Its amount is the exact sum of frozen positive `K` requirements; immutable allocations cover each such disposition once for exactly `K`, retaining the existing payout excess-slice attribution. There are no installments, waivers, payout reversals, future offsets, or Deposit/Custody/Receivable movements from recording the physical receipt, including no empty ledger transaction.

The authenticated recorder is also the physical receiver. Both the service and PostgreSQL require an active User with the ORDER_EMPLOYEE role and an explicit assignment to the historical Branch under lock. Global Super Admin authorization does not fabricate an employee receiver. Subsequent role or Branch changes do not rewrite the historical receiver. Only Super Admin may financially finalize: exact recovered coverage permits the existing unpaid-remainder retirement/residual events while leaving `O/P/C/U/R/K`, the original payout and customer refund untouched.

Private JPEG/PNG/WebP proof is mandatory, at most 5 MB, checked from actual bytes and stored with a random path and SHA-256 on `storage/app/captain-receivable-recoveries-private` (`serve=false`). Downloads reauthorize permission, historical Branch and nested correction/recovery ownership and verify the resolved physical path and stored bytes. Failed writes clean their proof; exact request-key/payload retries reuse the receipt, while changed facts or a second payment fail. PostgreSQL enforces immutable headers, source/receiver identity, canonical timezone-independent hashes, unique obligations and exact complete allocation sets.

The three forward-only 000400/000410/000420 migrations keep recording and positive-K completion closed during partial deployment. Activation locks the relevant financial source tables, validates existing disposition history without repair, then enables the complete contract atomically. Recovery locks Order, Invoice/Collection/Correction, exact source rows and Currency; recording acquires no FinancialAccount lock. A delivered paid correction can follow the controlled CANCEL_AND_REFUND path only with the existing refund, full evidenced Shop returns and all required recoveries; delivery and payout history remain visible and completed bill payments still forbid cancellation. Manual financial adjustments remain a separate future phase.
