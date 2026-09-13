<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-900 leading-tight">
            {{ __('Operations workspace') }}
        </h2>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="p-6 sm:p-8 text-slate-900">
                    <p class="text-sm font-semibold uppercase tracking-wider text-indigo-600">Abshir · Phase 1</p>
                    <h1 class="mt-2 text-2xl font-bold tracking-tight">Your workspace is ready.</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                        Your navigation reflects your assigned permissions. Branch access is ready for the operational modules added in later phases.
                    </p>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
