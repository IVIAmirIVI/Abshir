@props(['value', 'required' => false])

<label {{ $attributes->merge(['class' => 'text-start block font-medium text-sm text-gray-700']) }}>
    {{ $value ?? $slot }}
    @if($required)<span class="text-red-700" aria-hidden="true">*</span><span class="sr-only">{{ __('common.required_field') }}</span>@endif
</label>
