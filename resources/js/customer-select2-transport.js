export function normalizeCustomerResults(data) {
    // Select2 4.1's AJAX adapter maps its normalizer without binding `this`.
    // Vite's strict modules expose that bug. Normalize with the adapter context
    // first so every customer already has its generated result ID.
    return { results: data.results.map((item) => this._normalizeItem(item)) };
}

export function customerSearchTransport(select, wire, timeoutMs = 15000) {
    return (params, success, failure) => {
        const term = (params.data?.term || '').trim();
        let aborted = false;
        let finished = false;
        let timer;
        const request = {
            abort: () => {
                aborted = true;
                clearTimeout(timer);
            },
        };

        // The initial options have already been authorized and rendered by Livewire.
        // Opening the list should never wait for a redundant server round trip.
        if (term === '') {
            success({ results: Array.from(select.options)
                .filter((option) => option.value !== '')
                .map((option) => ({ id: option.value, text: option.text.trim() })) });
            return request;
        }

        const fail = () => {
            if (aborted || finished) return;
            finished = true;
            clearTimeout(timer);
            failure();
        };
        timer = setTimeout(fail, timeoutMs);
        Promise.resolve().then(() => wire.$call('searchCustomers', term)).then((results) => {
            if (aborted || finished) return;
            if (!Array.isArray(results)) {
                fail();
                return;
            }
            finished = true;
            clearTimeout(timer);
            success({ results });
        }).catch(fail);

        return request;
    };
}
