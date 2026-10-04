<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Support\CurrentWorld::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Livewire\Livewire::addPersistentMiddleware([
            \App\Http\Middleware\RequireWorld::class,
        ]);
        // Legacy import commands use unscoped SQL. Disable them until they are world-aware.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Console\Events\CommandStarting::class, function ($event) {
            if (! $event->command || in_array($event->command, ['world:adopt-legacy', 'world:seed-demo'], true)) {
                return;
            }
            $command = \Illuminate\Support\Facades\Artisan::all()[$event->command] ?? null;
            if ($command && str_starts_with(get_class($command), 'App\\Console\\Commands\\')) {
                throw new \LogicException('Legacy football commands are disabled until their SQL is world-scoped.');
            }
        });
    }
}
