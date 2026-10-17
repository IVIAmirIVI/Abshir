<button {{ $attributes->merge(['type' => 'submit', 'class' => 'app-button app-button--primary bg-brand-primary border border-transparent text-white  hover:bg-brand-hover focus:bg-brand-hover active:bg-brand-hover focus:outline-none focus:ring-2 focus:ring-brand-primary focus:ring-offset-2 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
