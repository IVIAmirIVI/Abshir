# Captain Portal and session API contract (Phases 14–15)

## Implemented access

GET /captain-portal and /captain-portal/orders/{id} resolve CaptainSelfView from the authenticated active User on every request. A Captain ID supplied in query input never changes identity, including mixed SUPER_ADMIN/CAPTAIN accounts. There is no new permission grant, write endpoint, evidence download, payout, deposit, settlement or collection action.

Current work requires an unremoved assignment, an employed Captain, an open Order and an active current home/temporary operational Branch. Only this scope receives immutable Order delivery contact snapshots, Shop contact/milestones, requested item quantities and receipt-presence metadata. Only the current PRIMARY_COLLECTION assignment sees its current issued customer Invoice's collection status/amount. No image paths, evidence hashes, staff notes, unrelated customer records, other Captain balances or company shares are projected.

Former/final/off-Branch participation keeps minimal Order number/status/date/role history. The existing historical participation service retains transfer/inactive-Branch history. Shared participation uses the same simultaneous-assignment SQL as the existing history. Order counts are distinct; participation is not a second Order. Status history is capped at 20; Order lists at 20 with SQL filtering. Shop items are fetched in one query, not per Shop.

The existing /my-captain-information page remains the authoritative read-only self-financial/performance view. Deposit, Receivable and Custody remain separate, with original global-self versus branch-administrative disclosure. Posted benefit/reversal/payout events remain visible through existing movements. Captain role currently has zero administrative permissions: bonus/expense proposal review history, private supporting documents and payout screens therefore remain unavailable to ordinary Captains. This task does not grant access to them.

## Proposed operational permissions — NOT activated

Owner approval is required before exposing Captain purchase, pickup, delivery, Collection, bill execution, benefit submission or evidence-download actions. Approval must specify role, Branch/assignment scope, removal/transfer behavior, evidence and lifecycle guards, immutable attribution, retry/concurrency contracts and downstream accounting. The existing employee Workflow remains the operational entry point.

## API foundation — disabled by default

No Sanctum/token guard, external-client agreement or token lifetime/revocation policy exists. No new dependency or token issuance was introduced. The prepared interface uses the existing **web session** and active-user checks only:

- GET /api/v1/me — authenticated Captain's own user ID/name/Captain ID.
- GET /api/v1/captain/orders — the same own active/history filtered list, capped at 20.
- GET /api/v1/captain/orders/{id} — minimal own participation projection.

CAPTAIN_SESSION_API_ENABLED defaults to false. Authenticated requests receive 404 while disabled. All routes require auth:web and throttle:60,1. Anonymous JSON requests receive 401. Foreign and nonexistent Orders both receive 404. POST/PUT/DELETE are absent. Responses use explicit resource fields and private/no-store, never serialize Order/User models, contact data, Invoice amounts, private attachments, internal notes or ledger payloads. Validation returns 422; use Accept: application/json. Instants are ISO UTC; browser presentation remains Gregorian/Western digits in Asia/Damascus.

### Separate activation requirements

1. Owner explicitly approves the same-origin, read-only session API scope.
2. Deployment uses HTTPS, secure HttpOnly SameSite cookies, restricted trusted proxies and no permissive credentialed CORS. Maintain normal session login/logout; no token/client login is implied.
3. Review rate-limit cache durability/capacity and access logs without credentials/query secrets.
4. Run the focused API authorization/identity/filter tests and consolidated UAT.
5. Set CAPTAIN_SESSION_API_ENABLED=true in the target's protected environment and rebuild config cache. Never change the current development .env automatically.

External/mobile bearer-token access remains blocked pending an explicit client/token/permission contract and owner approval; enabling this flag alone cannot provide that authentication.

## Targeted hardening result

Reviewed recent Reports/CSV, notifications, benefit approval and Shop settlement boundaries. Existing dedicated permissions, Branch guards, source UUID/hash replay handling, exact amounts and immutable SQL guards remain unchanged. CSV uses a minimal projection, UTF-8 BOM, formula-neutralized text and lazy 500-row chunks; report pagination is 25. No unsupported income/cash/net-profit inference was added. Scheduler outbox recovery remains every minute with withoutOverlapping; actual delivery remains recipient-reauthorized and idempotent.

No newly confirmed financial/security defect justified changing those services. Financial rollback, duplicate approval, Shop payment replay and unsupported shared payout/collected correction paths are included in the final focused verification selection. Browser Wizard stability is still unproven.
