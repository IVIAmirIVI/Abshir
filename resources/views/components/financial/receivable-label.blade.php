@props(['eventType', 'paid' => false])
{{ __(match ($eventType) {
    'CAPTAIN_BONUS_DUE' => $paid ? 'financial.ui.bonus_payout' : 'financial.ui.approved_bonus',
    'CAPTAIN_EXPENSE_REIMBURSEMENT_DUE' => $paid ? 'financial.ui.expense_payout' : 'financial.ui.approved_expense',
    'CUSTOMER_CREDIT_PERSONAL_FUNDS_DUE' => $paid ? 'financial.terms.receivable_payout' : 'financial.events.CUSTOMER_CREDIT_PERSONAL_FUNDS_DUE',
    default => $paid ? 'financial.terms.receivable_payout' : 'financial.ui.entitlement',
}) }}
