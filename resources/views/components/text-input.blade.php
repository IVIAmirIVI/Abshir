@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 focus:border-brand-primary focus:ring-brand-primary rounded-md shadow-sm']) }}>
