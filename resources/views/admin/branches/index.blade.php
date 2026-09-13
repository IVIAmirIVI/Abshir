<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Branches</h2></x-slot>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6 min-w-0">
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <div class="flex flex-wrap items-center justify-between gap-4"><p class="text-gray-600">Branches available to your account.</p>@can('branches.create')<a class="rounded-md bg-gray-800 px-4 py-2 text-white" href="{{ route('admin.branches.create') }}">Create branch</a>@endcan</div>
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        @forelse($branches as $branch)
            <article class="min-w-0 rounded-lg bg-white p-5 shadow space-y-3">
                <h3 class="font-semibold break-all">{{ $branch->name }}</h3>
                <p class="text-sm break-all">{{ $branch->code }} · <span class="{{ $branch->is_active ? 'text-green-700' : 'text-red-700' }}">{{ $branch->is_active ? 'Active' : 'Inactive' }}</span></p>
                <p class="text-sm text-gray-600 break-words">{{ $branch->address ?: 'No address provided' }}</p><p class="text-sm break-all">{{ $branch->phone }}</p>
                <div class="flex flex-wrap gap-4 items-center">
                    @can('branches.update')<a class="underline text-sm" href="{{ route('admin.branches.edit', $branch) }}">Edit branch</a>@endcan
                    @can($branch->is_active ? 'branches.deactivate' : 'branches.activate')
                    <form method="POST" action="{{ route('admin.branches.status', $branch) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $branch->is_active ? 0 : 1 }}"><button class="underline text-sm">{{ $branch->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                    @endcan
                </div>
            </article>
        @empty<p>No branches are available.</p>@endforelse
        </div>
        {{ $branches->links() }}

    </div>
</x-app-layout>

