<button {{ $attributes->merge(['type' => 'button', 'class' => 'app-button app-button--secondary bg-white border border-gray-300 text-gray-700  shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-primary focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
