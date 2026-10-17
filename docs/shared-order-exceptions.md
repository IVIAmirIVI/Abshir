# Shared Order exceptions — development implementation

Baseline: `livewire-ux`, `21b5df8`. This change is not development-database activation, browser acceptance, a release or merge approval.

## Supported operations

- Select one/two Captains and explicitly select the collector using the existing audited selection. Changes before binding retain previous assignments and require the existing reason/permissions. Purchase, Invoice or any bill-payment request binds participation; no automatic payer/collector replacement occurs afterward.
- Request optional external bill payments before delivery, without needing the principal yet. An effective configured fee rule is still required. Pending requests do not prevent a genuine shared Shop purchase. Completion still requires DELIVERED, verified evidence, reference, exact principal/Currency and the original request's fee rule.
- A shared external payment uses only the audited selected collector. Workflow displays that collector without another selection. Server and PostgreSQL reject another participant, unsupported financial source types and mismatched branches. The existing three bill-payment events are reused unchanged: principal Custody increase, authoritative principal handover Custody decrease, and company-owned service fee Deposit decrease. They do not add product reimbursement, delivery entitlement, customer Invoice charges or a new settlement UI.
- SYP tiered fees remain fixed 10 below 300, fixed 20 below 1000, then the currently owner-approved 3% of the entire principal rounded to the nearest 5 HALF_UP. Preview, completion and database enforcement retain the existing exact calculator. The owner deliberately versioned the SYP percentage from the original 2% policy to 3%; 1500 principal now yields 45 fee. Historical requests remain bound to their original rule version. This change does not introduce another Currency policy.
- Before-purchase cancellation closes pending shared bill requests with the actor/time and cancellation reference in the same transaction, preserving selections/history and creating no financial event.
- Existing authorized Invoice void/replacement is available for an uncollected shared issued Invoice with no Invoice-derived financial transactions. Replacement creates the next version from the same authoritative immutable purchases/pricing. Original items, amounts, participants, allocation rows and financial snapshot/hash are not rewritten. A retired uncollected snapshot remains verifiable. Retiring a version cannot authorize collecting it afterward.

## Precisely blocked cases

- A shared Shop return and AFTER_PURCHASE cancellation are blocked. Existing return records identify one purchasing/refund-recipient Captain, while the immutable shared purchase has two actual payers. The collector is not a substitute payer. Supporting these cases needs an approved multi-recipient return/refund evidence contract, including attribution of partial refunds/shortfalls; no distribution is guessed or routed automatically to the collector.
- Collected shared Invoice corrections/customer refunds and reversal of shared claims remain blocked by application and PostgreSQL. They need approved actual refund/recovery attribution for the collector's Custody and each payer's unpaid obligations. Existing single-Captain correction/refund sources stay unchanged.
- Captain/collector changes after source binding remain blocked; immutable history is never moved to a replacement Captain. Existing shared payout/settlement operations remain disabled. Internal company settlement policy does not create evidence that balances were physically settled.

## Activation and regression safety

New forward-only migrations, following the unchanged six activated migrations:

1. `2026_10_09_060000_enable_shared_collector_bill_payments.php`: extends the existing boundaries/resolver only for canonical bill-payment events, enforces the selected collector, and permits pending service requests before shared purchases.
2. `2026_10_09_070000_allow_uncollected_shared_invoice_versions.php`: permits audited uncollected retirement without changing frozen financial facts, preserving verification of retired historical snapshots.

Neither migration rewrites data or disables constraints/triggers. Installed-function extensions require an exact expected anchor and fail atomically on an unexpected definition. Without activation, the application shows an unavailable message and fails closed. Do not apply either migration to `abshir` without separate owner approval. No fee rule is seeded or activated.

Tests use disposable schemas in verified `abshir_testing`. Independent PHP/Laravel workers run real completion/cancellation/replacement/collection services, and the test observes both workers blocked in PostgreSQL's parent-Order lock graph before release. Worker helpers contain no duplicated financial or lifecycle rules. Demo reset is not executed; shared deletion compatibility remains separately unverified.
