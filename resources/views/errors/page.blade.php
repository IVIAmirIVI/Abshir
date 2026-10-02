<x-guest-layout>
    <section class="space-y-4 text-center" role="alert">
        <x-ltr class="text-3xl font-bold">{{ $status }}</x-ltr>
        <h1 class="text-xl font-semibold">{{ __('errors.titles.'.$status) }}</h1>
        @if($status === 409 && isset($exception) && $exception->getMessage())
            <p class="break-words">{{ $exception->getMessage() }}</p>
        @else
            <p>{{ __('errors.help') }}</p>
        @endif
        <a class="inline-block rounded px-3 py-2 underline focus:ring-2" href="{{ url('/') }}">{{ __('errors.home') }}</a>
    </section>
</x-guest-layout>
