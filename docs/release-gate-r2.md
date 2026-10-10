# Release gate R2 — operational safety preflight

Date: 2026-10-10. Initial branch: `livewire-ux`. Initial HEAD:
`3f351457e66fff3c3fff5e351caea5d1954feca0`. Initial working tree and index were clean.

This is a focused development review before the single consolidated browser UAT,
not production readiness, merge approval or browser acceptance. R1 was not repeated.

## Examined boundaries

- Authentication and inactive accounts: web middleware, permission Gates, SUPER_ADMIN
  bypass, default ORDER_EMPLOYEE/CAPTAIN permissions and delegated roles.
- Customers/phones/addresses: accessible-address visibility, creator exception,
  nested ownership, duplicate-phone privacy and old/new Area authorization.
- Shops/catalog: branch-scoped Shop queries and mutations, Area-less restrictions,
  nested product/image/group ownership. Catalog Products are global master data;
  their existing permission checks must not be replaced with invented Branch scope.
- Orders: HTTP routes and direct Livewire actions, authoritative draft/creation
  services, locked state, search selection, unchanged/genuine Branch updates,
  validation-to-step routing, retry lock/key, review-context comparison, atomic
  creation and server-side revalidation of contacts/Shops/products/Captains.
- Workflow/evidence: nested Shop/item ownership, private receipt/download guards,
  dual-purpose evidence transaction, actual pickup/delivery prerequisites, issued
  Invoice freezes, cancellation intent and final-state guards.
- Captains: assignment/selection services, explicit collector, source binding,
  replacement restrictions, authenticated-only self-view, scoped employee financial
  reads and separate global owner/self balances.
- Financial mutations: pricing exceptions/discounts, Invoice/Collection,
  correction preparation/abandonment/finalization and refund records. Shared
  purchase/Invoice/collection/bill paths and existing unsupported shared exceptions
  were inspected without creating a settlement workflow.

Existing regression coverage was inventoried before adding tests. CLAUDE.md,
the UAT backlog, shared-order exceptions and fee-policy documentation were read.
No `.ai/rules` directory or installed `testing-best-practices` skill was present;
the existing PHPUnit/fixture conventions were reused. Laravel Boost was already installed.

## Confirmed defects and fixes

| Severity | Evidence before fix | Minimal fix |
| --- | --- | --- |
| P1 — cross-Branch address modification | An employee can see a Customer through an address in Branch A. GET edit for that Customer's Branch B address correctly returns 403, but a forged PUT changing its Area to A succeeds. The save service checks only the requested Area. | `CustomerAddressService::save()` now checks access to the existing persisted Area after reloading/locking the address, before assigning the new Area. |
| P1 — cross-Branch delegated financial mutation | A custom role with the existing prepare/finalize/refund permission and access only to Branch B can POST prepare, abandon, refund or finalize for an existing Branch A correction. These services check permission but not Order Branch access. Default employees do not receive these elevated permissions; delegation made the defect reachable. | Preparation, abandonment, finalization and refund recording now authorize the locked Order through `Order::accessibleTo($actor)` before reading/mutating correction sources. |

The new seven-case regression initially produced **1 pass / 6 failures**, reproducing
the missing denials. It covers HTTP and direct production services, full before/after
contents of ten operational/financial tables and unchanged refund evidence files.
It also proves local address editing, authorized Super Admin relocation and the
existing delegated same-Branch correction/refund/finalization path remain available.

No P0 was found. No unresolved P1 remains within the inspected scope.
No additional release-blocking P2 code defect was established.

## Preserved financial and workflow contracts

- No permission grants, calculations, account types, financial event identities,
  migrations, constraints, historical snapshots or lifecycle rules changed.
- The added authorization reads retain the existing parent Order first lock order;
  no account lock or new financial posting path was introduced.
- Shared Orders retain one Invoice, one selected collector, immutable physical
  purchase responsibility, two delivery units and the combined discount once.
- Deposit, Company Receivable and Cash Custody stay separate. CASH and SHAM_CASH
  retain their distinct attribution. No external settlement is presumed.
- Shared post-purchase returns/cancellation and collected shared corrections/refunds
  remain blocked. Enabling them would require owner-approved payer/refund attribution;
  R2 neither needs nor implements that policy decision.
- Current configured upper SYP fee remains **3%**, nearest 5 HALF_UP. Fee rules,
  the calculator and database functions were untouched. The existing version-binding
  regression exercises 1500 -> 45 under 3%, retaining the older 2% request's history.
  The documented original 2% form preset is not an active-policy override; inspect
  the intended percentage when explicitly creating a new schedule.

## Verification

All PostgreSQL invocations used `tests/Support/run_isolated_pgsql.php`; its arguments
are forwarded to PHPUnit (`--filter`, `--log-junit`). It verifies configured and actual
database identity before creating a randomly named schema in `abshir_testing`, applies
test fixtures only there, then removes that generated schema in `finally`.

| Selection | Passed | Failed | Skipped | Assertions |
| --- | ---: | ---: | ---: | ---: |
| Existing authorization/customer/privacy/self-view/Wizard/Workflow/shared boundaries | 199 | 0 | 0 | 2216 |
| New regression before production fix (expected reproduction) | 1 | 6 | 0 | 26 |
| Related customer/correction/recovery/Invoice/shared tests after fix | 114 | 2 | 0 | 1058 |
| Final R2/pricing/Order/table/financial visibility/bill/concurrency selection | 131 | 0 | 0 | 1362 |

The two intermediate failures compared a just-created Eloquent attribute array
with a fresh database row (missing nullable fields/key order). Both now snapshot a
fresh persisted row; this was a test assertion correction, not another application
change. The final selection confirms all **7 new regressions pass / 50 assertions**,
including both corrected comparisons. Its **22 concurrency cases pass / 207
assertions**. The remaining 109 related existing cases had already passed with
1014 assertions and no production code changed after that run.

Quality: all four changed/new PHP files pass syntax; scoped Pint formatting passes;
Blade compilation and `git diff --check` exit 0. No frontend assets/dependencies/translations were changed;
R1's complete build/localization gate was not unnecessarily rerun. No full suite ran.
Ignored machine-local logs/XML contain test evidence and are not checkpoint files.

Safety clarification: the initial existing integration selection included its
no-interaction reset **refusal** regression. That test invokes the Artisan command
inside `abshir_testing`, expects exit 1 before cleanup, and asserts unchanged Orders.
No reset cleanup ran. Subsequent selections exclude this test. No reset invocation
against an owner database, development write, migration, seed or preview access occurred.

## Consolidated browser UAT and owner decisions

The intermittent browser draft loss remains **unresolved/unreproduced**. Inspection
and PHP regressions do not prove request/network/DOM stability. No Wizard patch,
sleep, request coordinator or speculative state infrastructure was added in R2.

Use one consolidated 375px/1440px session to verify:

1. Immediate Customer/contact Next, explicit Shop/Product Add then immediate Next,
   all four steps and repeated Back/Next; pending searches, inline creation and errors.
   Compare visible selections with the authoritative draft; capture entity IDs/action
   order only if failure recurs. Test unchanged and genuinely changed Branch separately.
2. Review-versus-created facts, duplicate submission/retry, stale selections and
   automatic/approved/pending override distinction.
3. One/two Captains, explicit higher-ID collector, actual purchase/evidence/pickup,
   one Invoice, CASH versus SHAM_CASH, optional bills and 3% current-policy display.
4. Restricted roles, manipulated object IDs, foreign Branches, Captain self-view,
   immutable final/issued facts and clear messages for unsupported shared exceptions.

No new owner financial decision is required for the approved first-release flow.
Keep unsupported shared returns/corrections blocked; do not turn them into a new
development phase. R2 can proceed to owner review and the single consolidated UAT
following the passing final targeted verification. It does not authorize deployment or merge.

## Checkpoint scope

Intended uncommitted files: three services, one focused regression test, this report
and the material R2 addition to `docs/uat-backlog.md`. No secrets, environment files,
generated build assets, dependencies or migrations are part of this change.
No staging, commit, push, merge, stash, reset or branch switch was performed.

Final `git status --short`:

```text
 M app/Services/CustomerAddressService.php
 M app/Services/CustomerRefundService.php
 M app/Services/InvoiceFinancialCorrectionService.php
 M docs/uat-backlog.md
?? docs/release-gate-r2.md
?? tests/Feature/ReleaseGateR2AuthorizationTest.php
```

Branch and HEAD remain `livewire-ux` / `3f35145`; the index is empty.

Verdict: **READY FOR REVIEW**. Proceed to owner review and the single consolidated
browser UAT; production readiness remains unclaimed.
