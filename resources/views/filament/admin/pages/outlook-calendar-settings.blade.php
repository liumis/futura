<?php

use App\Filament\Admin\Pages\OutlookCalendarSettings;

/** @var OutlookCalendarSettings $this */

?>

<x-filament-panels::page>
    @if (session('success'))
        <div class="mb-4 rounded-lg bg-success-50 p-3 text-sm text-success-700 dark:bg-success-400/10 dark:text-success-400">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-4 rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
            {{ session('error') }}
        </div>
    @endif

    {{ $this->form }}
</x-filament-panels::page>
