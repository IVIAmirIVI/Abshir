import assert from 'node:assert/strict';
import test from 'node:test';
import { customerSearchTransport, normalizeCustomerResults } from '../../resources/js/customer-select2-transport.js';

const select = { options: [{ value: '', text: 'Choose customer' }, { value: '6', text: ' Visible customer ' }] };
const flush = () => new Promise((resolve) => setImmediate(resolve));

test('results are normalized with the Select2 adapter context and unique accessible result IDs', () => {
    const adapter = {
        prefix: 'customer-select2-',
        _normalizeItem(item) {
            assert.equal(this, adapter);
            return { ...item, id: String(item.id), _resultId: this.prefix + item.id };
        },
    };
    const result = normalizeCustomerResults.call(adapter, { results: [{ id: 6, text: 'Visible customer' }, { id: 9, text: 'Remote customer' }] });
    assert.deepEqual(result.results.map((item) => item._resultId), ['customer-select2-6', 'customer-select2-9']);
    assert.deepEqual(result.results.map((item) => item.id), ['6', '9']);
    assert.deepEqual(normalizeCustomerResults.call(adapter, { results: [] }), { results: [] });
});

test('opening the list immediately displays authorized options without a server call', () => {
    let result;
    customerSearchTransport(select, { $call: () => assert.fail('Redundant initial lookup') })
        ({ data: { term: '' } }, (value) => { result = value; }, () => assert.fail('Initial lookup failed'));
    assert.deepEqual(result, { results: [{ id: '6', text: 'Visible customer' }] });
});

test('typed search uses the authorized Livewire method', async () => {
    let result;
    const wire = { $call: async (method, term) => {
        assert.equal(method, 'searchCustomers');
        assert.equal(term, '955');
        return [{ id: '9', text: 'Remote customer' }];
    } };
    customerSearchTransport(select, wire)({ data: { term: ' 955 ' } }, (value) => { result = value; }, () => assert.fail());
    await flush();
    assert.deepEqual(result, { results: [{ id: '9', text: 'Remote customer' }] });
});

test('failed, synchronous-error and invalid-payload requests leave the loading state', async () => {
    for (const $call of [() => { throw new Error('Dispatch failed'); }, async () => { throw new Error('Request failed'); }, async () => undefined]) {
        let failures = 0;
        customerSearchTransport(select, { $call })({ data: { term: 'name' } }, () => assert.fail(), () => { failures++; });
        await flush();
        assert.equal(failures, 1);
    }
});

test('a stalled request times out once and ignores a late response', async () => {
    let resolve;
    let failures = 0;
    customerSearchTransport(select, { $call: () => new Promise((done) => { resolve = done; }) }, 5)
        ({ data: { term: 'name' } }, () => assert.fail('Late result accepted'), () => { failures++; });
    await new Promise((done) => setTimeout(done, 20));
    assert.equal(failures, 1);
    resolve([]);
    await flush();
    assert.equal(failures, 1);
});

test('aborted searches ignore stale results and do not display errors', async () => {
    let resolve;
    const request = customerSearchTransport(select, { $call: () => new Promise((done) => { resolve = done; }) }, 5)
        ({ data: { term: 'name' } }, () => assert.fail('Stale result accepted'), () => assert.fail('Aborted search errored'));
    await flush();
    request.abort();
    resolve([]);
    await new Promise((done) => setTimeout(done, 20));
});
