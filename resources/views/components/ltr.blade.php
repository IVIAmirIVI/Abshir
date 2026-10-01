<bdi dir="ltr" {{ $attributes->except('dir')->merge(['class' => 'ui-technical']) }}>{{ $slot }}</bdi>
