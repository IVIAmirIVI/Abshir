@props(['eventType', 'paid' => false])
{{ __(match ($eventType) {
    'CAPTAIN_BONUS_DUE' => $paid ? 'financial.ui.bonus_payout' : 'financial.ui.approved_bonus',
    'CAPTAIN_EXPENSE_REIMBURSEMENT_DUE' => $paid ? 'financial.ui.expense_payout' : 'financial.ui.approved_expense',
    default => $paid ? 'financial.terms.receivable_payout' : 'financial.ui.entitlement',
}) }}
