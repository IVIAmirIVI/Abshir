<?php

namespace App\Livewire\Shell;

use App\Support\ApplicationNavigation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class NavigationFinder extends Component
{
    public bool $open = false;

    public string $search = '';

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
    }

    public function close(): void
    {
        $this->open = false;
        $this->search = '';
    }

    #[Computed]
    public function links(): array
    {
        abort_unless(auth()->check(), 403);
        $links = [['route' => 'dashboard', 'label' => __('admin.navigation.dashboard')]];
        foreach (ApplicationNavigation::groups(auth()->user()) as $group) {
            array_push($links, ...$group['items']);
        }
        $links[] = ['route' => 'profile.edit', 'label' => __('admin.navigation.profile')];
        $search = mb_strtolower(trim(mb_substr($this->search, 0, 100)));

        return array_values(array_filter($links, fn ($link) => $search === '' || str_contains(mb_strtolower($link['label']), $search)));
    }

    public function render(): View
    {
        return view('livewire.shell.navigation-finder');
    }
}
