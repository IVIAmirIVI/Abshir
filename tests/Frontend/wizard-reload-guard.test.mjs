import assert from 'node:assert/strict';
import test from 'node:test';
import { installWizardReloadGuard } from '../../resources/js/wizard-reload-guard.js';

test('an active order wizard pauses Vite reload until the document leaves, including the success redirect', () => {
    let listener;
    let notices = 0;
    let draft;
    installWizardReloadGuard({ on: (event, callback) => {
        assert.equal(event, 'vite:beforeFullReload');
        listener = callback;
    } }, () => draft, () => notices++);

    assert.equal(listener(), undefined); // Another application page.
    draft = { customerId: '', groups: {}, createdOrderId: null };
    assert.equal(listener(), undefined); // Empty wizard.
    draft = { customerId: '8', groups: { shop: { items: [{ quantity: '3' }] } }, createdOrderId: null };
    const original = structuredClone(draft);
    const pending = listener();
    assert.ok(pending instanceof Promise);
    assert.equal(listener(), pending);
    assert.equal(notices, 1);
    assert.deepEqual(draft, original);

    draft.createdOrderId = 17;
    assert.equal(listener(), pending); // The old document can still be at /orders/create.
    assert.equal(draft.createdOrderId, 17);
    draft = undefined;
    assert.equal(listener(), undefined); // The workflow destination is not held.
});

test('the first reload arriving after persistence cannot replace the pending workflow redirect', () => {
    let listener;
    const draft = { customerId: '8', groups: {}, createdOrderId: 17 };
    installWizardReloadGuard({ on: (_, callback) => { listener = callback; } }, () => draft, () => {});
    assert.ok(listener() instanceof Promise);
    assert.equal(draft.createdOrderId, 17);
});
