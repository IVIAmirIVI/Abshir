// Display only. The production service revalidates exact allocations and totals.
export function allocationTotal(values) {
    const parts = values.map(value => String(value).trim()).filter(Boolean);
    if (parts.some(value => !/^\d+(?:\.\d+)?$/.test(value))) return null;
    const scale = Math.max(0, ...parts.map(value => (value.split('.')[1] || '').length));
    const total = parts.reduce((sum, value) => {
        const [whole, fraction = ''] = value.split('.');
        return sum + BigInt(whole + fraction.padEnd(scale, '0'));
    }, 0n).toString().padStart(scale + 1, '0');
    if (!scale) return total;
    return `${total.slice(0, -scale)}.${total.slice(-scale)}`.replace(/\.?0+$/, '');
}

function updateTotal(form) {
    const inputs = [...form.querySelectorAll('[data-payout-allocation]')];
    const total = allocationTotal(inputs.map(input => input.value));
    form.querySelector('[data-payout-total]').value = total ?? '';
    for (const input of inputs) {
        input.setCustomValidity(allocationTotal([input.value]) === null ? form.dataset.invalidAmount : '');
    }
}

if (typeof document !== 'undefined') {
    const initialize = () => document.querySelectorAll('[data-captain-payout]').forEach(updateTotal);
    document.addEventListener('input', event => {
        if (event.target.matches('[data-payout-allocation]')) updateTotal(event.target.closest('form'));
    });
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-payout-fill]');
        if (!button) return;
        const input = document.getElementById(button.dataset.payoutFill);
        input.value = button.dataset.remaining;
        updateTotal(input.closest('form'));
        input.focus();
    });
    document.addEventListener('DOMContentLoaded', initialize);
    document.addEventListener('livewire:navigated', initialize);
    initialize();
}
