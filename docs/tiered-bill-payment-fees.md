# External bill-payment fee schedules

The existing fee administration page supports fixed fees and SYP tiered schedules.
The default tier form contains the owner-approved schedule:

| Positive principal (SYP) | Fee |
| --- | --- |
| Below 300 | 10 SYP |
| From 300 to below 1000 | 20 SYP |
| From 1000 | 2% of the entire principal, rounded to the nearest multiple of 5 SYP (halfway up) |

These defaults are form configuration, not seeded or automatically activated rules.
An authorized administrator must explicitly save a schedule with an effective date.
Tier ranges must cover all positive principals with no gaps or overlaps. The existing
per-currency effective-interval exclusion still prevents competing active versions.
Other currencies retain their existing fixed-fee behavior.

## Snapshot and accounting boundary

A request may be initiated without a principal or Captain. It binds the immutable
fee-rule version effective at request initiation. Fixed requests retain their existing
numeric fee snapshot. Tiered requests have a NULL numeric fee, meaning undetermined,
and retain their rule identity unchanged throughout their lifecycle.

When payment is recorded after delivery, the production service selects exactly one
tier from that bound version using the actual principal. Percentage calculation uses
BigDecimal: `round_HALF_UP(principal * percentage / 500) * 5`, with one rounding
operation and no intermediate rounding to Currency precision. PostgreSQL
independently enforces the same result at insertion and deferred commit. Principal
inputs still must be exact at the configured Currency precision; no input rounding
or Currency precision changes are introduced.

Examples: 1025 → 20; 1100 → 20; 1125 → 25; 1250 → 25; 1350 → 25;
1375 → 30; 1500 → 30. Fixed fees and other financial amounts retain their
existing policy. Development SYP has zero decimal places, so fractional principals
remain invalid there; tier boundaries can be checked at four-decimal test precision.

The payment's service fee and total become immutable at the existing completion/
financial posting transaction. Rule versioning cannot change earlier requests or
completed payments. The existing Custody increase and handover decrease use only
principal; the existing Captain Deposit decrease uses the calculated service fee.
No delivery-pricing, Receivable, customer Invoice or Collection semantics change.

The Workflow's principal field previews the server-calculated principal, fee and total
without an additional click. The completion endpoint recomputes them and ignores any
submitted fee or total. Preview does not create financial facts.

## Development rollout

`2026_10_08_010000_add_tiered_bill_payment_fees.php` is additive and forward-only.
Do not apply it to development without explicit owner approval. Until it is applied,
the administration page explains the prerequisite and keeps tier configuration disabled.
Without an active rule, request initiation remains unavailable. No migration seeds or
classifies data, and no existing historical fee amount is rewritten.
