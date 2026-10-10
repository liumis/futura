<x-filament-panels::page>
    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <form wire:submit="create" class="space-y-4">
            {{ $this->form }}

            <div class="flex justify-end">
                <x-filament::button type="submit" size="lg">
                    Upload
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>
