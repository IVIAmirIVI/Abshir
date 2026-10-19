const { chromium } = require(process.env.WIZARD_PLAYWRIGHT_PATH);
const assert = require('node:assert/strict');
const fs = require('node:fs');

(async () => {
    const browser = await chromium.launch({ executablePath: process.env.WIZARD_CHROME_PATH, headless: true });
    const page = await browser.newPage();
    page.setDefaultTimeout(45000);
    const sequence = [];
    const errors = [];
    const responses = [];
    const navigations = [];
    const checkpoints = [];
    const responseTasks = [];
    const viteEvents = [];
    let heldReload;
    let viteSocket;
    if (process.env.WIZARD_BROWSER_RELOAD_PROBE) {
        await page.routeWebSocket(url => url.port === '5173', socket => {
            viteSocket = socket;
            const server = socket.connectToServer();
            server.onMessage(message => {
                const payload = JSON.parse(String(message));
                if (payload.type === 'full-reload') {
                    heldReload = message;
                    viteEvents.push({ type: payload.type, path: payload.path, held: true });
                } else socket.send(message);
            });
        });
    }
    page.on('websocket', socket => socket.on('framereceived', frame => {
        try {
            const data = JSON.parse(String(frame.payload));
            if (['full-reload', 'error', 'update'].includes(data.type)) viteEvents.push({ type: data.type });
        } catch {}
    }));
    page.on('pageerror', e => errors.push(e.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    page.on('framenavigated', frame => { if (frame === page.mainFrame()) navigations.push(frame.url()); });
    page.on('requestfailed', request => errors.push({ url: request.url(), failure: request.failure() }));
    page.on('response', response => {
        if (response.request().method() !== 'POST' || !response.url().includes('/livewire')) return;
        responseTasks.push((async () => {
            const payload = await response.json().catch(() => null);
            responses.push({ status: response.status(), timing: response.request().timing(), components: (payload?.components ?? []).map(component => {
                const snapshot = JSON.parse(component.snapshot);
                return { id: snapshot.memo.id, name: snapshot.memo.name, step: snapshot.data.step,
                    branchId: snapshot.data.branchId, customerId: snapshot.data.customerId,
                    customerPhoneId: snapshot.data.customerPhoneId, customerAddressId: snapshot.data.customerAddressId,
                    errors: snapshot.memo.errors, redirect: component.effects?.redirect };
            }) });
        })());
    });
    page.on('request', req => {
        if (req.method() === 'POST' && req.url().includes('/livewire')) {
            const data = req.postDataJSON();
            for (const component of data.components ?? []) {
                const snapshot = JSON.parse(component.snapshot);
                if (snapshot.memo.name === 'orders.create-order') sequence.push({ id: snapshot.memo.id, step: snapshot.data.step, updates: component.updates, calls: component.calls.map(c => c.method) });
            }
        }
    });
    const base = process.env.WIZARD_BROWSER_URL;
    const state = () => page.evaluate(() => {
        const el = document.querySelector('[data-order-create-wizard]');
        if (!el?.__livewire) return null;
        const data = el.__livewire.reactive;
        return JSON.parse(JSON.stringify({ step: data.step, branchId: data.branchId, customerId: data.customerId, customerPhoneId: data.customerPhoneId, customerAddressId: data.customerAddressId, source: data.source, groups: data.groups, submissionKey: data.submissionKey, wizardCaptainIds: data.wizardCaptainIds, wizardCollectorId: data.wizardCollectorId, specialDeliveryAssignments: data.specialDeliveryAssignments, specialAddonApprovals: data.specialAddonApprovals, deliveryOverrideAmount: data.deliveryOverrideAmount, deliveryOverrideReason: data.deliveryOverrideReason }));
    });
    const model = name => page.locator('input,select,textarea').locator(`xpath=self::*[@*[starts-with(name(), 'wire:model')]='${name}']`);
    const action = name => page.locator(`[wire\\:click="${name}"]`);
    const step = value => page.waitForFunction(v => document.querySelector('[data-order-create-wizard]')?.__livewire?.reactive.step === v, value);
    const checkpoint = async label => {
        const draft = await state();
        const id = await page.locator('[data-order-create-wizard]').getAttribute('wire:id');
        checkpoints.push({ label, id, state: draft, url: page.url() });
        return { id, ...draft };
    };
    try {
        const identity = await (await page.request.get(base + '/__wizard-test-identity')).json();
        assert.equal(identity.database, 'abshir_testing');
        assert.equal(identity.environment, 'testing');
        assert.match(identity.schema, /^mc_testing_[a-f0-9]{16}$/);
        await page.goto(base + '/login');
        await page.locator('[name="email"]').fill(process.env.WIZARD_BROWSER_EMAIL);
        await page.locator('[name="password"]').fill('password');
        await Promise.all([page.waitForURL('**/dashboard'), page.locator('button[type="submit"]').click()]);
        let runs = 0;
        const scenarios = process.env.WIZARD_BROWSER_SCENARIOS?.split(',') || ['normal', 'normal', 'inline', 'special', 'special'];
        for (const width of [1440, 375]) {
            await page.setViewportSize({ width, height: 960 });
            for (const [repeat, scenario] of scenarios.entries()) {
                await page.goto(base + '/orders/create');
                await step(1);
                if (await model('branchId').evaluate(el => el.tagName === 'SELECT')) await model('branchId').selectOption(process.env.WIZARD_BRANCH_ID);
                else assert.equal(await model('branchId').inputValue(), process.env.WIZARD_BRANCH_ID);
                await model('customerSearch').fill('أمير');
                await action(`selectCustomer(${process.env.WIZARD_CUSTOMER_ID})`).click();
                await page.waitForFunction(id => document.querySelector('[data-order-create-wizard]').__livewire.reactive.customerId === id, process.env.WIZARD_CUSTOMER_ID);
                await model('customerPhoneId').selectOption(process.env.WIZARD_PHONE_ID);
                await model('customerAddressId').selectOption(process.env.WIZARD_ADDRESS_ID);
                if (repeat === 0) {
                    await action('nextStep(1)').click();
                    await page.locator('[aria-invalid="true"]').first().waitFor();
                    assert.equal((await state()).customerId, process.env.WIZARD_CUSTOMER_ID);
                    assert.equal((await state()).step, 1);
                }
                await model('source').selectOption('PHONE');
                await action('nextStep(1)').click();
                await step(2);
                if (scenario === 'inline' && await action('openShopCreate').count()) {
                    await action('openShopCreate').click();
                    await model('newShopName').fill(`UAT inline shop ${width}`);
                    await model('newShopClassification').selectOption('SHOP');
                    await action('createPermanentShop').click();
                } else {
                    await model('shopSearch').fill('محل');
                    await action(`selectShop(${process.env.WIZARD_SHOP_ID})`).click();
                }
                await action('addDraftShop').click();
                await model('productSearch').waitFor();
                if (repeat === 1) {
                    await action('nextStep(2)').click();
                    await page.locator('[role="alert"]').filter({ hasText: 'أضف' }).first().waitFor();
                    const invalid = await state();
                    assert.equal(invalid.step, 2);
                    assert.equal(invalid.customerId, process.env.WIZARD_CUSTOMER_ID);
                    assert.equal(Object.keys(invalid.groups).length, 1);
                }
                const special = scenario === 'special';
                await model('productSearch').fill(special ? 'خاص' : 'حليب');
                if (repeat === 0) await model('productSearch').press('Enter');
                else await action(`selectProduct(${special ? process.env.WIZARD_SPECIAL_ID : process.env.WIZARD_PRODUCT_ID})`).click();
                await model('quantity').fill('2');
                await action('addDraftItem').click();
                if (special) {
                    await model('shopSearch').fill('إضافي');
                    await action(`selectShop(${process.env.WIZARD_SECOND_SHOP_ID})`).click();
                    await action('addDraftShop').click();
                }
                await model('productSearch').fill('خبز');
                if (repeat === 0) await model('productSearch').press('Enter');
                else await action(`selectProduct(${process.env.WIZARD_SECOND_PRODUCT_ID})`).click();
                await model('quantity').fill('3');
                await action('addDraftItem').click();
                const beforeCaptainStage = await checkpoint('before-next-to-captains');
                await action('nextStep(2)').click();
                if (special) {
                    await page.locator('[role="alert"]').filter({ hasText: 'يلزم اعتماد صريح' }).first().waitFor();
                    const data = await state();
                    assert.equal(data.step, 2);
                    const keys = Object.keys(data.groups);
                    await model(`addonInputs.${keys[1]}.source_key`).selectOption(keys[0] + '_0');
                    await model(`addonInputs.${keys[1]}.reason`).fill('UAT same-trip explicit approval');
                    await action(`approveDraftAddon('${keys[1]}')`).click();
                    await action('nextStep(2)').click();
                }
                await step(3);
                const captainStage = await checkpoint('captain-stage-loaded');
                assert.equal(captainStage.id, beforeCaptainStage.id);
                assert.equal(captainStage.submissionKey, beforeCaptainStage.submissionKey);
                for (const field of ['customerId', 'customerPhoneId', 'customerAddressId', 'branchId', 'source']) assert.deepEqual(captainStage[field], beforeCaptainStage[field]);
                assert.deepEqual(Object.keys(captainStage.groups), Object.keys(beforeCaptainStage.groups));
                assert.deepEqual(Object.values(captainStage.groups).flatMap(group => group.items.map(item => item.quantity)), ['2', '3']);
                const candidates = JSON.parse(process.env.WIZARD_CAPTAIN_IDS);
                const ids = scenario === 'single' ? candidates.slice(0, 1) : candidates;
                await model('wizardCaptainIds.0').selectOption(ids[0]);
                if (ids.length === 2) {
                    await action('addSecondWizardCaptain').click();
                    await model('wizardCaptainIds.1').selectOption(ids[1]);
                    await model('wizardCollectorId').selectOption(ids[1]);
                }
                await page.waitForFunction(id => document.querySelector('[data-order-create-wizard]').__livewire.canonical.wizardCollectorId === id, ids.at(-1));
                await checkpoint('captains-selected');
                if (process.env.WIZARD_BROWSER_RELOAD_PROBE) {
                    const before = await checkpoint('before-observed-vite-reload');
                    const messageOrigin = heldReload ? 'captured-real-message' : 'baseline-recording-replay';
                    assert.ok(viteSocket, 'The real Vite client must be connected for the reload regression.');
                    // Replay the protocol captured in the before-fix reset, even on a warm dev server.
                    viteSocket.send(heldReload || JSON.stringify({ type: 'full-reload', path: '*' }));
                    await page.locator('[data-wizard-reload-paused]').waitFor({ state: 'visible' });
                    const after = await checkpoint('after-observed-vite-reload');
                    assert.deepEqual(after, before);
                    await page.screenshot({ path: process.env.WIZARD_BROWSER_SESSION_PATH + `/guarded-reload-${width}-${scenario}.png`, fullPage: true });
                    fs.writeFileSync(process.env.WIZARD_BROWSER_SESSION_PATH + `/reload-probe-${width}-${scenario}.json`, JSON.stringify({ result: 'PASS', messageOrigin, before, after, viteEvents, navigations }));
                }
                if (special) {
                    const source = Object.keys((await state()).groups)[0] + '_0';
                    await model(`specialDeliveryAssignments.${source}.${ids[0]}`).fill('1');
                    await model(`specialDeliveryAssignments.${source}.${ids[1]}`).fill('1');
                }
                await page.locator('[data-wizard-override] summary').click();
                await action('beginDeliveryOverride').click();
                await model('deliveryOverrideAmount').fill(special ? '275' : '8');
                await model('deliveryOverrideReason').fill('UAT explicit additional effort');
                await action('nextStep(3)').click();
                await step(4);
                const draft = await state();
                assert.deepEqual(draft.wizardCaptainIds.map(String), ids);
                assert.equal(draft.wizardCollectorId, ids.at(-1));
                assert.deepEqual(Object.values(draft.groups).flatMap(group => group.items.map(item => item.quantity)), ['2', '3']);
                if (special) assert.equal(Object.keys(draft.specialAddonApprovals).length, 1);
                for (const next of [2, 1, 2, 3, 2, 3]) {
                    await action(`goToStep(${next})`).first().click();
                    await step(next);
                    const after = await state();
                    assert.deepEqual({ ...after, step: 2 }, { ...draft, step: 2 });
                    if (next === 1) {
                        for (const field of ['customerId', 'customerPhoneId', 'customerAddressId', 'source']) assert.equal(await model(field).inputValue(), draft[field]);
                    }
                }
                runs++;
                console.log(JSON.stringify({ width, run: repeat + 1, result: 'PASS' }));
                if (repeat === scenarios.length - 1) await page.screenshot({ path: process.env.WIZARD_BROWSER_SESSION_PATH + `/wizard-${width}.png`, fullPage: true });
            }
        }
        assert.deepEqual(errors, []);
        await Promise.all(responseTasks);
        assert.ok(responses.every(response => response.status === 200));
        const result = { result: 'PASS', runs, widths: [1440, 375], role: process.env.WIZARD_BROWSER_ROLE || 'SUPER_ADMIN', assets: process.env.WIZARD_USE_VITE ? 'vite' : 'compiled', requests: sequence.length, responses, navigations, checkpoints, sequence, viteEvents };
        fs.writeFileSync(process.env.WIZARD_BROWSER_SESSION_PATH + '/browser-result.json', JSON.stringify(result));
        console.log(JSON.stringify({ result: result.result, runs, requests: sequence.length, role: result.role }));
    } catch (error) {
        await Promise.all(responseTasks);
        await page.screenshot({ path: process.env.WIZARD_BROWSER_SESSION_PATH + '/failure.png', fullPage: true }).catch(() => {});
        fs.writeFileSync(process.env.WIZARD_BROWSER_SESSION_PATH + '/browser-failure.json', JSON.stringify({ message: error.message, state: await state().catch(() => null), sequence, responses, navigations, checkpoints, errors, viteEvents }));
        console.error(JSON.stringify({ result: 'FAIL', message: error.message, state: await state().catch(() => null), sequence: sequence.slice(-12), errors }));
        process.exitCode = 1;
    } finally {
        await browser.close();
    }
})();
