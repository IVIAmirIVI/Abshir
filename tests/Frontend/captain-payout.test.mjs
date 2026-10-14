import test from 'node:test';
import assert from 'node:assert/strict';
import { allocationTotal } from '../../resources/js/captain-payout.js';

test('empty, partial, full and multiple source totals preserve exact decimals', () => {
    assert.equal(allocationTotal(['', '0']), '0');
    assert.equal(allocationTotal(['200.0000']), '200');
    assert.equal(allocationTotal(['7.25', '12.75']), '20');
    assert.equal(allocationTotal(['0.1', '0.2']), '0.3');
    assert.equal(allocationTotal(['99999999999999999.9999', '0.0001']), '100000000000000000');
    assert.equal(allocationTotal([' 1.2345 ', '2.0000']), '3.2345');
});

test('invalid, negative and non-finite allocations never yield a usable total', () => {
    for (const value of ['-1', 'NaN', 'Infinity', '1e3', '2,000', '٢', '1.']) {
        assert.equal(allocationTotal(['20', value]), null);
    }
});
