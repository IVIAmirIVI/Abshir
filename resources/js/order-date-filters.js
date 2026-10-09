import AirDatepicker from 'air-datepicker';
import { syrianDateLocale } from './syrian-date-locale';
import localeEn from 'air-datepicker/locale/en';
import { localCalendarDate } from './calendar-date';

const initialized = new WeakMap();

export function initializeOrderDates(component) {
    if (!component.el.matches('[data-orders-table]')) return;
    component.el.querySelectorAll('[data-order-date-filter]').forEach((input) => {
        if (initialized.has(input)) return;
        const field = input.dataset.orderDateFilter;
        if (!['from', 'to'].includes(field)) return;
        const panel = input.closest('#orders-index-filters');
        const picker = new AirDatepicker(input, {
            locale: document.documentElement.lang.startsWith('ar') ? syrianDateLocale : localeEn,
            dateFormat: 'yyyy-MM-dd',
            autoClose: true,
            container: panel,
            classes: 'abshir-order-date-calendar',
            buttons: ['clear'],
            onSelect({ formattedDate }) {
                const value = formattedDate || '';
                if (component.$wire.$get(field) !== value) component.$wire.$set(field, value, true);
            },
            // Supported positioning callback keeps either field's popup inside the viewport.
            position({ $datepicker, $target, $pointer }) {
                const target = $target.getBoundingClientRect();
                const parent = panel.getBoundingClientRect();
                const width = $datepicker.offsetWidth;
                const height = $datepicker.offsetHeight;
                const left = Math.max(12, Math.min(target.left, window.innerWidth - width - 12));
                const top = target.bottom + height + 8 > window.innerHeight && target.top > height + 8
                    ? target.top - height - 8 : target.bottom + 8;
                $datepicker.style.left = `${left - parent.left}px`;
                $datepicker.style.top = `${top - parent.top}px`;
                $pointer.style.display = 'none';
            },
        });
        const sync = (value) => {
            const iso = String(value || '');
            const selected = picker.selectedDates;
            if (input.value === iso && (iso === '' ? selected.length === 0
                : selected.length === 1 && picker.formatDate(selected[0], 'yyyy-MM-dd') === iso)) return;
            picker.clear({ silent: true });
            const date = localCalendarDate(iso);
            if (date) picker.selectDate(date, { silent: true });
            input.value = iso;
        };
        sync(component.$wire.$get(field));
        const unwatch = component.$wire.$watch(field, sync);
        const clear = () => { picker.clear(); if (picker.visible) picker.hide(); };
        const clearButton = input.parentElement.querySelector('[data-clear-order-date]');
        clearButton.addEventListener('click', clear);
        initialized.set(input, picker);
        component.addCleanup(() => {
            unwatch();
            clearButton.removeEventListener('click', clear);
            picker.destroy();
            initialized.delete(input);
        });
    });
}

function register() {
    window.Livewire.hook('component.init', ({ component }) => queueMicrotask(() => initializeOrderDates(component)));
    window.Livewire.hook('morphed', ({ component }) => initializeOrderDates(component));
    window.Livewire.all().forEach(initializeOrderDates);
}

if (window.Livewire) register();
else document.addEventListener('livewire:init', register, { once: true });
