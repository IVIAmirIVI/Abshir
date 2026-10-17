import test from 'node:test';
import assert from 'node:assert/strict';
import { localCalendarDate } from '../../resources/js/calendar-date.js';
import { syrianDateLocale } from '../../resources/js/syrian-date-locale.js';

test('ISO calendar dates retain their local parts at year/month/leap boundaries', () => {
    for (const iso of ['2026-01-01', '2026-12-31', '2026-10-09', '2028-02-29', '0099-01-01']) {
        const date = localCalendarDate(iso);
        assert.ok(date);
        assert.equal(`${String(date.getFullYear()).padStart(4, '0')}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`, iso);
        assert.equal(date.getHours(), 0);
    }
});
test('clear and invalid calendar values are not normalized into another day', () => {
    for (const iso of ['', '2026-02-29', '2026-13-01', '2026-00-09', '2026-10-32', '2026-10-00', '٢٠٢٦-١٠-٠٩']) assert.equal(localCalendarDate(iso), null);
});
test('datepicker uses Syrian names, corrected weekdays and Western ISO format', () => {
    assert.equal(syrianDateLocale.months[9], 'تشرين الأول');
    assert.equal(syrianDateLocale.months[0], 'كانون الثاني');
    assert.equal(syrianDateLocale.days[1], 'الاثنين');
    assert.equal(syrianDateLocale.days[5], 'الجمعة');
    assert.equal(syrianDateLocale.dateFormat, 'yyyy-MM-dd');
    assert.equal(syrianDateLocale.timeFormat, 'HH:mm');
});
