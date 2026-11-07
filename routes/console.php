<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Schedule::call(function () {
    $disk = \Illuminate\Support\Facades\Storage::disk('local');
    foreach (['packages/tmp', 'packages/staged'] as $directory) {
        foreach ($disk->allFiles($directory) as $path) {
            if ($disk->lastModified($path) < time() - 7200) {
                $disk->delete($path);
            }
        }
    }
})->hourly()->name('packages:cleanup')->withoutOverlapping();
