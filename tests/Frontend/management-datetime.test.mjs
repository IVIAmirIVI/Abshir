import test from 'node:test';
import assert from 'node:assert/strict';
import { wallClockDate, formatWallClock, damascusNow, todayWallClock, validityEnd } from '../../resources/js/management-datetime.js';

test('business wall-clock parts stay unchanged, including seconds, without UTC conversion', () => {
    const date = wallClockDate('2026-10-14T13:15:27.123456');
    assert.deepEqual([date.getFullYear(), date.getMonth() + 1, date.getDate(), date.getHours(), date.getMinutes(), date.getSeconds()], [2026, 10, 14, 13, 15, 27]);
    assert.equal(wallClockDate('2026-10-14T13:15').getHours(), 13);
    assert.equal(formatWallClock(date), '2026-10-14T13:15');
});

test('Today uses Damascus across UTC midnight, retaining a selected time', () => {
    const instant = new Date('2026-10-14T22:30:00Z');
    assert.equal(damascusNow(instant), '2026-10-15T01:30');
    assert.equal(todayWallClock('', instant), '2026-10-15T01:30');
    assert.equal(todayWallClock('2026-09-01T14:30:27.123456', instant), '2026-10-15T14:30:27.123456');
});

test('all duration shortcuts populate exact wall-clock end values from start', () => {
    const start = '2026-10-14T14:30:27.123456';
    const dates = { day: '2026-10-15', week: '2026-10-21', month: '2026-11-14', halfYear: '2027-04-14', year: '2027-10-14' };
    for (const [duration, date] of Object.entries(dates)) assert.equal(validityEnd(start, duration), `${date}T14:30:27.123456`);
    assert.equal(validityEnd(start, 'none'), '');
    assert.equal(validityEnd('', 'none'), '');
    assert.equal(validityEnd('', 'week'), null);
    assert.equal(validityEnd('invalid', 'month'), null);
});

test('month and year shortcuts clamp to calendar end, including leap years', () => {
    assert.equal(validityEnd('2026-01-31T14:30', 'month'), '2026-02-28T14:30');
    assert.equal(validityEnd('2028-01-31T14:30', 'month'), '2028-02-29T14:30');
    assert.equal(validityEnd('2028-02-29T14:30', 'year'), '2029-02-28T14:30');
    assert.equal(validityEnd('2026-08-31T14:30', 'halfYear'), '2027-02-28T14:30');
    assert.equal(validityEnd('2026-12-31T14:30', 'day'), '2027-01-01T14:30');
});

test('empty and invalid typed values are not normalized into another date', () => {
    for (const value of ['', '2026-02-30T13:15', '2026-10-14T25:15', '2026-10-14T13:60', '2026-10-14T13:15Z']) {
        assert.equal(wallClockDate(value), null);
    }
});
