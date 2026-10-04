<?php

namespace App\Providers;

use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    public function boot(): void
    {
        Window::open('football')
            ->title('Football')
            ->route('worlds.index')
            ->width(1440)
            ->height(960)
            ->minWidth(1000)
            ->minHeight(720);
    }

    public function phpIni(): array
    {
        return ['memory_limit' => '512M'];
    }
}
