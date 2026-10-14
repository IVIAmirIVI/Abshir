import AirDatepicker from 'air-datepicker';
import localeEn from 'air-datepicker/locale/en.js';
import { syrianDateLocale } from './syrian-date-locale.js';

const initialized = new WeakSet();
const initializedPresets = new WeakSet();

export function damascusNow(instant = new Date()) {
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Asia/Damascus', year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hourCycle: 'h23', numberingSystem: 'latn',
    }).formatToParts(instant);
    const part = (type) => parts.find((value) => value.type === type).value;
    return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}`;
}

export function todayWallClock(value, instant = new Date()) {
    const current = damascusNow(instant);
    return wallClockDate(value) ? current.slice(0, 10) + value.slice(10) : current;
}

export function validityEnd(start, duration) {
    if (duration === 'none') return '';
    const match = /^(\d{4})-(\d{2})-(\d{2})(T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?)$/.exec(start);
    if (!match || !wallClockDate(start)) return null;
    const [, year, month, day, time] = match;
    // UTC is only a calendar arithmetic workspace, never an instant conversion.
    const date = new Date(Date.UTC(+year, +month - 1, +day));
    if (duration === 'day' || duration === 'week') date.setUTCDate(date.getUTCDate() + (duration === 'day' ? 1 : 7));
    else {
        const months = { month: 1, halfYear: 6, year: 12 }[duration];
        if (!months) return null;
        date.setUTCDate(1);
        date.setUTCMonth(date.getUTCMonth() + months);
        const last = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0)).getUTCDate();
        date.setUTCDate(Math.min(+day, last));
    }
    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}${time}`;
}

export function formatWallClock(date) {
    const pad = (value) => String(value).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

// Treat date parts as a wall clock, never convert business time through UTC.
export function wallClockDate(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,6}))?)?$/.exec(value);
    if (!match) return null;
    const [, year, month, day, hour, minute, second = '0'] = match;
    const date = new Date(+year, +month - 1, +day, +hour, +minute, +second);
    return date.getFullYear() === +year && date.getMonth() === +month - 1
        && date.getDate() === +day && date.getHours() === +hour
        && date.getMinutes() === +minute && date.getSeconds() === +second ? date : null;
}

export function initializeManagementDates(root = document) {
    root.querySelectorAll('[data-management-datetime]').forEach((input) => {
        if (initialized.has(input)) return;
        initialized.add(input);
        const arabic = document.documentElement.lang.startsWith('ar');
        let syncing = false;
        let seconds = '';
        const picker = new AirDatepicker(input, {
            locale: arabic ? syrianDateLocale : (localeEn.default || localeEn),
            showEvent: 'click',
            dateFormat: (date) => formatWallClock(date) + seconds, timeFormat: 'HH:mm',
            timepicker: true, toggleSelected: false,
            buttons: [{
                content: arabic ? syrianDateLocale.today : 'Today', attrs: { type: 'button' },
                onClick() {
                    input.value = todayWallClock(input.value);
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    picker.setViewDate(wallClockDate(input.value));
                },
            }, 'clear'],
            classes: 'abshir-management-calendar',
            onSelect() {
                if (!syncing) {
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            },
            onShow() {
                sync();
                picker.$datepicker.querySelectorAll('input[type="range"]').forEach((range) => {
                    const label = range.name === 'hours'
                        ? (arabic ? 'الساعة' : 'Hour') : (arabic ? 'الدقيقة' : 'Minute');
                    range.setAttribute('aria-label', label);
                    if (!range.parentElement.querySelector('.management-time-label')) {
                        const text = document.createElement('span');
                        text.className = 'management-time-label';
                        text.textContent = label;
                        range.before(text);
                    }
                });
            },
            position({ $datepicker, $target, $pointer }) {
                const rect = $target.getBoundingClientRect();
                const left = Math.max(8, Math.min(rect.left, innerWidth - $datepicker.offsetWidth - 8));
                const top = rect.bottom + $datepicker.offsetHeight + 8 > innerHeight
                    ? Math.max(8, rect.top - $datepicker.offsetHeight - 8) : rect.bottom + 8;
                $datepicker.style.left = `${left + scrollX}px`;
                $datepicker.style.top = `${top + scrollY}px`;
                $pointer.style.display = 'none';
            },
        });
        const sync = async () => {
            const original = input.value;
            seconds = /T\d{2}:\d{2}(:\d{2}(?:\.\d{1,6})?)$/.exec(original)?.[1] || '';
            syncing = true;
            const date = wallClockDate(original);
            if (date) {
                await picker.selectDate(date, { silent: true, updateTime: true });
                picker.setViewDate(date);
            }
            else await picker.clear({ silent: true });
            // Preserve existing seconds/microseconds and empty/invalid values on reload.
            input.value = original;
            syncing = false;
        };
        sync();
        input.addEventListener('keydown', (event) => {
            if (event.altKey && event.key === 'ArrowDown') {
                event.preventDefault();
                picker.show();
            }
        });
        input.addEventListener('change', () => { if (!syncing) sync(); });
        // Keep keyboard focus transfer into the popup from closing it on input blur.
        input.addEventListener('blur', (event) => {
            if (picker.$datepicker.contains(event.relatedTarget)) event.stopImmediatePropagation();
        }, true);
        document.addEventListener('focusin', (event) => {
            if (picker.visible && event.target !== input && !picker.$datepicker.contains(event.target)) picker.hide();
        });
    });
    root.querySelectorAll('[data-end-presets]').forEach((controls) => {
        if (initializedPresets.has(controls)) return;
        initializedPresets.add(controls);
        controls.addEventListener('click', (event) => {
            const button = event.target.closest('[data-end-duration]');
            if (!button) return;
            const form = controls.closest('form');
            const start = form.querySelector('[name="effective_from"]');
            const end = form.querySelector('[name="effective_until"]');
            const value = validityEnd(start.value, button.dataset.endDuration);
            const status = controls.querySelector('[data-preset-status]');
            status.textContent = value === null ? controls.dataset.startRequired : '';
            if (value === null) { start.focus(); return; }
            end.value = value;
            end.dispatchEvent(new Event('input', { bubbles: true }));
            end.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => initializeManagementDates());
    document.addEventListener('livewire:navigated', () => initializeManagementDates());
}
