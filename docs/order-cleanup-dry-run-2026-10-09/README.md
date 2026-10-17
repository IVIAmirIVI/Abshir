# Order cleanup preview: BLOCKED

This is a historical pre-reset investigation, not the current reset command acceptance report. preview-audit.json is retained as private local evidence and excluded from Git: it includes operational/financial details, attachment references and large schema fingerprints. Do not force-add it or database/storage backups. The reusable reset was implemented subsequently; see ../development-demo-order-reset.md for its separate contract.

Preview: `abshir_cleanup_preview`, owner `abshir_app`. Backup restored successfully with PostgreSQL 18 `pg_restore --single-transaction --exit-on-error` into a verified empty database. No --clean/--create, migrations, trigger/FK disabling, TRUNCATE or application bootstrapping was used. Development `abshir` and test `abshir_testing` were never connected to during this resumed task.

The runtime schema is public: 19 Orders. The full backup also includes 18 older non-public schemas with 5 additional Orders. Those are namespaces inside the preview, not connections to the test database. All non-public data remains outside the proposed application Order cleanup scope.

## Blocker and tested outcome

Active PostgreSQL guards unconditionally reject deleting financial transactions, financial entries, committed Invoice Collections, completed purchases, financial source snapshots, completed bill payments/evidence/handovers and correction history. In particular, financial_transaction_delete_guard -> prevent_financial_transaction_delete() raises SQLSTATE 55000: Financial transactions cannot be deleted. financial_entries_immutable raises SQLSTATE 55000: Posted financial history is immutable. purchase_immutable raises SQLSTATE 23514: Completed order purchase, intent set, return or evidence is immutable.

The preview-only preflight script exits 2 and stops BEFORE DELETE. No destructive cleanup script is authorized or supplied. Deleting parent Orders first would also encounter restrictive FKs; deleting children first cannot bypass the immutable source guards. No bypass was attempted.

Two committed read-only snapshots checked all 1,382 restored tables using full-row SHA-256 fingerprints. Every count and content fingerprint is identical. All 258 public FKs were explicitly checked: zero dangling rows. All 11 public financial source references resolve to existing public Orders; zero unresolved source links. Zero records or files were deleted. MC1 migration is absent, as required; the preview reflects the actual backup.

## Exact dependent counts

| Table | Before | After | Deleted |
| --- | ---: | ---: | ---: |
| public.captain_ratings | 2 | 2 | 0 |
| public.captain_receivable_recoveries | 0 | 0 | 0 |
| public.captain_receivable_recovery_allocations | 0 | 0 | 0 |
| public.customer_refunds | 0 | 0 | 0 |
| public.delivery_fee_override_requests | 1 | 1 | 0 |
| public.invoice_collections | 6 | 6 | 0 |
| public.invoice_correction_financial_snapshots | 1 | 1 | 0 |
| public.invoice_correction_receivable_disposition_allocations | 0 | 0 | 0 |
| public.invoice_correction_receivable_dispositions | 0 | 0 | 0 |
| public.invoice_correction_reversals | 0 | 0 | 0 |
| public.invoice_financial_corrections | 1 | 1 | 0 |
| public.invoice_items | 10 | 10 | 0 |
| public.invoices | 9 | 9 | 0 |
| public.order_bill_payment_evidence | 2 | 2 | 0 |
| public.order_bill_payment_principal_handovers | 2 | 2 | 0 |
| public.order_bill_payment_requests | 3 | 3 | 0 |
| public.order_bill_payments | 2 | 2 | 0 |
| public.order_cancellation_intent_shops | 0 | 0 | 0 |
| public.order_cancellation_intents | 0 | 0 | 0 |
| public.order_cancellations | 0 | 0 | 0 |
| public.order_captains | 14 | 14 | 0 |
| public.order_delivery_allocations | 11 | 11 | 0 |
| public.order_delivery_pricing_routes | 10 | 10 | 0 |
| public.order_delivery_pricings | 11 | 11 | 0 |
| public.order_financial_source_snapshot_lines | 6 | 6 | 0 |
| public.order_financial_source_snapshots | 6 | 6 | 0 |
| public.order_item_changes | 0 | 0 | 0 |
| public.order_items | 21 | 21 | 0 |
| public.order_shop_purchase_confirmations | 11 | 11 | 0 |
| public.order_shop_receipts | 9 | 9 | 0 |
| public.order_shop_return_evidence | 0 | 0 | 0 |
| public.order_shop_returns | 0 | 0 | 0 |
| public.order_shops | 21 | 21 | 0 |
| public.order_status_history | 64 | 64 | 0 |
| public.orders | 19 | 19 | 0 |
| public.financial_transactions | 11 | 11 | 0 |
| public.financial_entries | 11 | 11 | 0 |
| public.captain_receivable_payout_allocations | 0 | 0 | 0 |

## Preserved master and independent financial records

| Table | Before | After |
| --- | ---: | ---: |
| customers | 6 | 6 |
| customer_phones | 6 | 6 |
| customer_addresses | 6 | 6 |
| shops | 5 | 5 |
| products | 5 | 5 |
| captains | 1 | 1 |
| captain_groups | 0 | 0 |
| branches | 1 | 1 |
| users | 2 | 2 |
| roles | 3 | 3 |
| permissions | 147 | 147 |
| user_roles | 2 | 2 |
| role_permissions | 64 | 64 |
| user_branches | 1 | 1 |
| areas | 2 | 2 |
| currencies | 1 | 1 |
| financial_owners | 1 | 1 |
| financial_accounts | 2 | 2 |
| delivery_pricing_rules | 10 | 10 |
| bill_payment_fee_rules | 1 | 1 |
| system_settings | 2 | 2 |
| captain_deposit_receipts | 0 | 0 |
| captain_deposit_manual_adjustments | 0 | 0 |
| captain_receivable_payouts | 0 | 0 |
| captain_receivable_recoveries | 0 | 0 |

There are no independent financial_transactions in this backup: all 11 public transactions map to the target Orders, and the other schemas contain zero financial transactions. Independent source tables above are empty and remain empty. Financial account/owner definitions are preserved. Other master tables and policies are also fingerprinted in preview-audit.json; no policy or active schedule was changed.

## Financial reconciliation

| Account kind | Before SYP | After SYP |
| --- | ---: | ---: |
| CAPTAIN_DEPOSIT | -322.0000 | -322.0000 |
| CAPTAIN_CASH_CUSTODY | 0.0000 | 0.0000 |
| CAPTAIN_COMPANY_RECEIVABLE | No account or entries | No account or entries |

Deposit effect = -277 from five Order company-delivery-share debits, plus -45 from two bill service-fee debits. Custody = +1495 from two external principal receipts and -1495 from two authoritative handovers. These are recorded financial facts; zero Custody does not mean its history may be deleted. Erasing all 11 events would mathematically reset Deposit to zero (a +322 change) and erase the paired handover history. That counterfactual was NOT executed and has no approved financial purge/reconciliation contract.

## Evidence and backups

22 attachment references were inventoried: 11 purchase proofs, 9 Shop receipts and 2 bill-payment proofs. Missing archive paths: 0. Exact relative paths and row IDs are recorded in preview-audit.json. No archive extraction or physical deletion was performed.

Database backup SHA-256: `3af638bc7fbafd6e02abede1fd883d4552055eb3cc2a498b697a85068799d6b3`. Storage backup SHA-256: `294bdbc1e77db0081e99536978607bab2d23583f16b1aff17a60542e2958dc8b`. Full pg_restore --list and tar listing returned exit 0.

## Script and next owner decision

Script: `scripts/order-cleanup-preview-audit.php`. Run `C:\tools\abshir-php\php.exe -c C:\tools\abshir-php\php.ini scripts/order-cleanup-preview-audit.php` from the repository. It always connects only to abshir_cleanup_preview, verifies owner and read-only state, does not bootstrap Laravel, and never executes DML or DDL. Exit 2 is the expected blocked result for this backup. It regenerates only this task's preview-audit.json.

Do NOT run any deletion on abshir. The owner must first choose between preserving/archiving this financial history, or separately authorizing design of a strictly development-only audited purge contract that changes the current immutable-history policy and defines exact balance reconciliation. Under the present prohibition on disabling guards, a plain controller change or reordered DELETE statements cannot solve the blocker. Any new policy/SQL guard changes require a separate reviewed task and fresh preview validation before final real-database approval. No such changes have been made here.

No staging, commit, push, reset, stash or migration. All existing uncommitted MC1 files were preserved.

