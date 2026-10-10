# UAT data setup and full demo reset assessment

Branch/baseline: `livewire-ux` / `11f95e4`. This is development setup, not browser
acceptance, release approval or final cleanup authorization.

## Verified pre-setup backup

Private ignored archive:
`storage/app/demo-order-reset-backups/abshir_before_demo_reset_20261010_065144_766b21c5f9df.dump`

- Database: `abshir/public`, local loopback PostgreSQL, owner `abshir_app`.
- Size: 10,652,512 bytes.
- SHA256: `afb4e3d6a52786c057a48ca9518b7ebd1021cc941ef4924edaf9e2350001a1e7`.
- pg_dump exit 0, PGDMP header, pg_restore --list exit 0, public Orders entry present.
- Adjacent verification/baseline JSON files contain metadata/hashes, not plaintext credentials.
- This verifies archive listability, not a restore drill. No restore was performed.
- Archives and private reports must never be committed.

## Dedicated added fixtures

| Record | ID | Purpose |
|---|---:|---|
| User | 7 | Second demo CAPTAIN, existing Branch 2 |
| Captain | 3 | AVAILABLE, open employment period, employee code UAT_CAPTAIN_2 |
| Employment period | 3 | Authoritative creation service opens employment for Captain 3 |
| Branch | 3 | UAT_BRANCH_B, active, existing SYP Currency 2 |
| Area | 6 | UAT_AREA_B in Branch 3 |
| Shop | 6 | UAT_SHOP_B, SHOP, Area 6 |
| Shop | 7 | UAT_RESTAURANT_A, RESTAURANT, existing Area 3 / Branch 2 |
| Customer | 9 | UAT_SHARED_CUSTOMER marker |
| Address | 9 | Accessible Area 4 / Branch 2 |
| Address | 10 | Foreign Area 6 / Branch 3 |
| Phone | 9 | Dedicated synthetic mobile fixture; never contact it |
| Delivery rule | 12 | Area 6 -> 6, SINGLE_SHOP, SYP 120, effective at setup |

Existing active Restaurant rules cover Area 3 -> 4. Existing SHOP/MULTI_SHOP
rules and all 3% bill fee configuration remain untouched. No product duplication
or UAT Order was needed. The existing Employee remains scoped only to Branch 2.

FormRequest validation and existing domain services were used. Branch creation
uses the same validated model persistence as the existing Branch controller, which
has no separate Branch service. Stable fixture codes/markers provide lookup before
creation. Prior row hashes were checked inside the setup transaction: no existing
row was changed and additions were restricted to the expected tables.

The new account has a random initial secret stored only as a password hash; no
credential was disclosed and no existing password was changed. The owner must
set/establish this new login through normal account management before testing it.
Do not attempt to retrieve its hash or reset other accounts automatically.

## Counts before / after

| Table | Before | After |
|---|---:|---:|
| users | 3 | 4 |
| captains | 1 | 2 |
| captain_employment_periods | 1 | 2 |
| branches | 1 | 2 |
| areas | 3 | 4 |
| shops | 5 | 7 |
| customers | 7 | 8 |
| customer_addresses | 7 | 9 |
| customer_phones | 7 | 8 |
| products | 5 | 5 |
| delivery_pricing_rules | 10 | 11 |
| bill_payment_fee_rules | 2 | 2 |
| financial_transactions / entries | 1 / 1 | 1 / 1 |
| Orders / Invoices / Collections | 0 / 0 / 0 | 0 / 0 / 0 |

Existing Captain Deposit remains 5000 SYP. No new financial Account/Owner, posting,
balance change, migration, seed, reset, storage deletion or UAT session occurred.
No connection was made to `abshir_cleanup_preview`.

## Full cleanup is blocked by the explicit owner stop condition

See `development-demo-order-reset.md` for the read-only command and precise
immutable-history boundary. The existing deposit is identified as demo, but that
does not disable its source/ledger DELETE guards. Full deletion would need an
additional reviewed exception and dependency contract, so it is not implemented.

Financial source -> transaction -> entries, reversal/version chains, receipt/
adjustment evidence, payout/recovery allocations and polymorphic source links must
remain consistent. Customer/Shop/catalog cleanup also needs exact FK coverage;
Shop-owned financial identities and independent Product/reference data must not
be silently removed. Physical evidence is retained by default. Current inventory
contains no preserved incoming FK row conflict, but that is not future deletion
compatibility evidence.

Next safe step: review fixture setup and establish the new Captain login, then
perform the already prepared single consolidated browser UAT. Choose the clean
go-live database approach or separately authorize a precisely scoped full demo
maintenance design afterward. No destructive execution is currently approved.

## Verification results

- Final focused runner: 11 passed, 56 assertions, zero failures/errors/skips.
- Includes nine new assessment/refusal cases and two existing Order-reset boundary regressions.
- Scoped Pint and PHP syntax checks passed; git diff --check passed.
- All 74 non-fixture public table fingerprints match the verified backup baseline.
- Both Captains 2 and 3 are available/active with open employment periods in Branch 2.
- Real development execution was limited to authorized fixture additions and SELECT-only assessment.
- No full cleanup was executed or proven; no preview database was contacted.
