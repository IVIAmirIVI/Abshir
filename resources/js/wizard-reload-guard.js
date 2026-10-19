export function installWizardReloadGuard(
    hot,
    getDraft = () => document.querySelector('[data-order-create-wizard]')?.__livewire?.reactive,
    notify = () => window.dispatchEvent(new Event('abshir-wizard-reload-paused')),
) {
    let postponedReload;

    hot.on('vite:beforeFullReload', () => {
        const draft = getDraft();
        if (!draft || (!draft.createdOrderId && !draft.customerId && !Object.keys(draft.groups ?? {}).length)) return;

        if (!postponedReload) {
            notify();
            // Vite awaits this listener. Keep automatic reload paused for this document;
            // A persisted ID does not mean the workflow redirect has finished yet.
            // Intentional navigation and the successful creation redirect still work.
            postponedReload = new Promise(() => {});
        }
        return postponedReload;
    });
}

if (import.meta.hot) installWizardReloadGuard(import.meta.hot);
