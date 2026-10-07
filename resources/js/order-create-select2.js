import $ from 'jquery';
import installSelect2 from 'select2';
import { customerSearchTransport, normalizeCustomerResults } from './customer-select2-transport.js';

installSelect2(window, $);

const formSelector = '[data-order-create-select2]';
const namespace = '.abshirOrderCreate';
const initializedComponents = new WeakSet();
const Results = $.fn.select2.amd.require('select2/results');

// Ambiguous customer searches require an explicit click/arrow choice, never a first-result fallback.
class CustomerResults extends Results {
    highlightFirstItem() {
        const choices = this.$results.find('.select2-results__option--selectable');
        if (choices.length === 1) super.highlightFirstItem();
        else this.$results.find('.select2-results__option--highlighted').removeClass('select2-results__option--highlighted');
    }
}
const messages = {
    ar: { noResults: () => 'لا توجد نتائج', searching: () => 'جارٍ البحث…', errorLoading: () => 'تعذّر تحميل الزبائن، حاول البحث مجددًا.' },
    en: { noResults: () => 'No results found', searching: () => 'Searching…', errorLoading: () => 'Could not load customers. Please search again.' },
};

function selects(component) {
    return component.el.matches(formSelector) ? component.el.querySelectorAll('select') : [];
}

function destroy(component) {
    selects(component).forEach((select) => {
        const widget = $(select);
        widget.off(namespace);
        if (widget.hasClass('select2-hidden-accessible')) widget.select2('destroy');
    });
}

function initialize(component) {
    if (!component.el.isConnected || !component.el.matches(formSelector)) return;
    if (!initializedComponents.has(component)) {
        initializedComponents.add(component);
        component.addCleanup(() => destroy(component));
    }

    selects(component).forEach((select) => {
        const model = Array.from(select.attributes).find((attribute) => attribute.name.startsWith('wire:model'));
        if (!model || $(select).hasClass('select2-hidden-accessible')) return;

        const widget = $(select);
        const direction = select.getAttribute('dir') || document.documentElement.dir || 'ltr';
        const language = document.documentElement.lang.startsWith('ar') ? 'ar' : 'en';
        widget.val(component.$wire.$get(model.value));
        const options = {
            width: '100%',
            dir: direction,
            language: messages[language],
            // Keep dropdown markup outside labels and the DOM managed by Livewire.
            dropdownParent: $(select.closest('[data-customer-create-modal]') || document.body),
            minimumResultsForSearch: 0,
        };
        if (model.value === 'customerId') {
            options.resultsAdapter = CustomerResults;
            options.ajax = {
                delay: 250,
                processResults: normalizeCustomerResults,
                transport: customerSearchTransport(select, component.$wire),
            };
        }
        widget.select2(options);
        widget.next('.select2-container').addClass('abshir-order-select2');
        const label = select.closest('label')?.querySelector('span')?.textContent.trim();
        widget.next('.select2-container').find('.select2-selection').attr({
            'aria-label': label || select.name || model.value,
            'aria-invalid': select.classList.contains('border-red-500') ? 'true' : 'false',
        });
        widget.on(`change${namespace}`, () => {
            const value = select.value;
            if (String(component.$wire.$get(model.value) ?? '') !== value) {
                component.$wire.$set(model.value, value, true);
            }
        });
    });
}

function register() {
    const livewire = window.Livewire;
    livewire.hook('component.init', ({ component }) => {
        queueMicrotask(() => initialize(component));
    });
    livewire.hook('morph', ({ component }) => destroy(component));
    livewire.hook('morphed', ({ component }) => queueMicrotask(() => initialize(component)));
    // The Vite module may load after Livewire has already initialized the page.
    livewire.all().forEach(initialize);
}

if (window.Livewire) register();
else document.addEventListener('livewire:init', register, { once: true });
