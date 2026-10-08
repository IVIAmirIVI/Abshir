<?php

namespace App\Providers;

use Mary\MaryServiceProvider;

class UiComponentServiceProvider extends MaryServiceProvider
{
    public function boot(): void
    {
        // Components only: editor uploads, Spotlight and session-toggle routes
        // are intentionally not enabled in Abshir.
        $this->registerComponents();
        $this->registerBladeDirectives();
    }
}
