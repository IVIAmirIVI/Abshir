const { chromium } = require(process.env.WIZARD_PLAYWRIGHT_PATH);
const assert = require('node:assert/strict');
const fs = require('node:fs');

(async () => {
    const browser = await chromium.launch({ executablePath: process.env.WIZARD_CHROME_PATH, headless: true });
    const page = await browser.newPage();
    page.setDefaultTimeout(45000);
    const base = process.env.WIZARD_BROWSER_URL;
    const evidence = process.env.WIZARD_BROWSER_SESSION_PATH;
    const errors = [];
    const requests = [];
    const runs = [];
    const redirectProbes = [];
    const probeEnabled = !!process.env.WIZARD_REDIRECT_PROBE;
    const redirectCoverage = !!process.env.WIZARD_REDIRECT_COVERAGE;
    const creationResponses = [];
    let viteSocket;
    let resolveProbe;
    let resolveRedirect;
    let probeInFlight = false;
    if (probeEnabled) {
        await page.exposeFunction('__wizardRedirectGuarded', () => {
            if (probeInFlight) resolveProbe('guarded');
        });
        await page.exposeFunction('__wizardRedirectReady', data => resolveRedirect(data));
        await page.addInitScript(() => window.addEventListener('abshir-wizard-reload-paused', () => window.__wizardRedirectGuarded()));
        await page.routeWebSocket(url => url.port === '5173', socket => {
            viteSocket = socket;
            const server = socket.connectToServer();
            server.onMessage(message => {
                if (JSON.parse(String(message)).type !== 'full-reload') socket.send(message);
            });
        });
        page.on('request', request => {
            if (probeInFlight && request.isNavigationRequest() && new URL(request.url()).pathname === '/orders/create') resolveProbe('reload');
        });
    }
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => {
        if (response.status() >= 400) errors.push(`${response.status()} ${response.url()}`);
    });
    await page.route('**/livewire-*/update', async route => {
        const response = await route.fetch();
        const payload = await response.json();
        for (const component of payload.components ?? []) {
            if (component.effects?.redirect) creationResponses.push({ id: JSON.parse(component.snapshot).data.createdOrderId, url: component.effects.redirect });
        }
        await route.fulfill({ response });
    });
    page.on('request', request => {
        if (request.method() === 'POST' && request.url().includes('/livewire')) {
            requests.push(request.postDataJSON().components.map(component => ({ updates: component.updates, calls: component.calls })));
        }
    });
    const model = name => page.locator('input,select,textarea').locator(`xpath=self::*[@*[starts-with(name(), 'wire:model')]='${name}']`);
    const action = name => page.locator(`[wire\\:click="${name}"]`);
    const step = value => page.waitForFunction(v => document.querySelector('[data-order-create-wizard]')?.__livewire?.reactive.step === v, value);
    try {
        const identity = await (await page.request.get(base + '/__wizard-test-identity')).json();
        assert.equal(identity.database, 'abshir_testing');
        assert.equal(identity.environment, 'testing');
        assert.match(identity.schema, /^mc_testing_[a-f0-9]{16}$/);
        await page.goto(base + '/login');
        await page.locator('[name="email"]').fill(process.env.WIZARD_BROWSER_EMAIL);
        await page.locator('[name="password"]').fill('password');
        await Promise.all([page.waitForURL('**/dashboard'), page.locator('button[type="submit"]').click()]);
        for (const width of [1440, 375]) {
          for (const scenario of (redirectCoverage ? ['normal', 'cylinder'] : ['cylinder'])) {
            await page.setViewportSize({ width, height: 960 });
            await page.goto(base + '/orders/create');
            await step(1);
            await model('branchId').selectOption(process.env.WIZARD_BRANCH_ID);
            await model('customerSearch').fill('أمير');
            await action(`selectCustomer(${process.env.WIZARD_CUSTOMER_ID})`).click();
            await page.waitForFunction(id => document.querySelector('[data-order-create-wizard]').__livewire.reactive.customerId === id, process.env.WIZARD_CUSTOMER_ID);
            await model('customerPhoneId').selectOption(process.env.WIZARD_PHONE_ID);
            await model('customerAddressId').selectOption(process.env.WIZARD_ADDRESS_ID);
            await model('source').selectOption('PHONE');
            await action('nextStep(1)').click();
            await step(2);
            await model('shopSearch').fill('محل');
            await action(`selectShop(${process.env.WIZARD_SHOP_ID})`).click();
            await action('addDraftShop').click();
            const special = scenario === 'cylinder';
            await model('productSearch').fill(special ? 'أسطوانة' : 'حليب');
            await action(`selectProduct(${special ? process.env.WIZARD_SPECIAL_ID : process.env.WIZARD_PRODUCT_ID})`).click();
            await model('quantity').fill('1');
            await action('addDraftItem').click();
            await action('nextStep(2)').click();
            await step(3);
            const captain = JSON.parse(process.env.WIZARD_CAPTAIN_IDS)[0];
            await model('wizardCaptainIds.0').selectOption(captain);
            await page.waitForFunction(id => document.querySelector('[data-order-create-wizard]').__livewire.canonical.wizardCollectorId === id, captain);
            await action('nextStep(3)').click();
            await step(4);
            assert.equal(await page.locator('html').getAttribute('dir'), 'rtl');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
            await page.screenshot({ path: `${evidence}/${scenario}-review-${width}.png`, fullPage: true });
            if (probeEnabled) {
                const redirect = new Promise(resolve => { resolveRedirect = resolve; });
                await page.evaluate(() => {
                    const root = document.querySelector('[data-order-create-wizard]');
                    root.__livewire.$wire.$interceptRequest(({ onRedirect }) => onRedirect(({ url, preventDefault }) => {
                        // Pause only in the test at Livewire's actual redirect boundary.
                        preventDefault();
                        window.__wizardRedirectReady({ url, createdOrderId: root.__livewire.reactive.createdOrderId });
                    }));
                });
                await action('submit').click();
                const destination = await redirect;
                assert.ok(destination.createdOrderId);
                assert.ok(viteSocket, 'A real Vite HMR client is required.');
                probeInFlight = true;
                const outcome = new Promise(resolve => { resolveProbe = resolve; });
                viteSocket.send(JSON.stringify({ type: 'full-reload', path: '*' }));
                const result = await outcome;
                probeInFlight = false;
                redirectProbes.push({ result, destination });
                if (result === 'reload') {
                    await step(1);
                    const blankDraft = await page.evaluate(() => {
                        const data = document.querySelector('[data-order-create-wizard]').__livewire.reactive;
                        return { step: data.step, customerId: data.customerId, groups: Object.keys(data.groups), createdOrderId: data.createdOrderId };
                    });
                    redirectProbes.at(-1).after = blankDraft;
                    assert.fail('Vite reloaded /orders/create after persistence, before the workflow redirect completed.');
                }
                await Promise.all([page.waitForURL(destination.url), page.evaluate(url => window.location.assign(url), destination.url)]);
            } else {
                await Promise.all([page.waitForURL(/\/orders\/\d+\/workflow/), action('submit').click()]);
            }
            const orderUrl = page.url();
            const persistedId = Number(new URL(orderUrl).pathname.match(/\/orders\/(\d+)\/workflow/)[1]);
            assert.equal(creationResponses.at(-1).id, persistedId);
            assert.equal(creationResponses.at(-1).url, orderUrl);
            if (redirectCoverage) {
                const submissions = requests.flat().flatMap(request => request.calls).filter(call => call.method === 'submit').length;
                await page.goBack();
                await page.waitForURL(base + '/orders/create');
                await page.goForward();
                await page.waitForURL(orderUrl);
                assert.equal(requests.flat().flatMap(request => request.calls).filter(call => call.method === 'submit').length, submissions);
                await action('advance').click();
                await page.waitForFunction(() => !!document.querySelector('[data-order-workflow]')?.__livewire?.reactive.actionStatus);
                assert.equal(page.url(), orderUrl);
            }
            await page.screenshot({ path: `${evidence}/${scenario}-created-${width}.png`, fullPage: true });
            runs.push({ width, scenario, result: 'PASS', orderUrl: page.url(), persistedId, continued: redirectCoverage });
            console.log(JSON.stringify(runs.at(-1)));
          }
        }
        await page.unrouteAll({ behavior: 'wait' });
        assert.deepEqual(errors, []);
        fs.writeFileSync(`${evidence}/cylinder-result.json`, JSON.stringify({ result: 'PASS', identity, runs, requests, errors, redirectProbes, creationResponses }, null, 2));
        console.log(JSON.stringify({ result: 'PASS', runs: runs.length, evidence }));
    } catch (error) {
        await page.screenshot({ path: `${evidence}/cylinder-failure.png`, fullPage: true }).catch(() => {});
        fs.writeFileSync(`${evidence}/cylinder-failure.json`, JSON.stringify({ message: error.message, runs, requests, errors, redirectProbes, url: page.url() }, null, 2));
        console.error(error);
        process.exitCode = 1;
    } finally {
        await page.unrouteAll({ behavior: 'wait' });
        await browser.close();
    }
})();
