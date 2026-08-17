@php
    /** @var \App\Models\Document $record */
    $files = $record->attachedFileLinks();
@endphp

<span class="inline-flex items-center justify-center gap-1">
    @if ($files === [])
        <span
            title="No files attached"
            class="fi-badge inline-flex items-center justify-center rounded-md bg-danger-600 px-1.5 py-0.5 text-white dark:bg-danger-500"
        >
            <x-heroicon-s-exclamation-circle class="h-4 w-4" />
        </span>
    @else
        @foreach ($files as $file)
            @if (filled($file['url']))
                <a
                    href="{{ $file['url'] }}"
                    target="_blank"
                    rel="noopener"
                    title="{{ $file['name'] }}"
                    class="inline-flex text-primary-600 hover:text-primary-500 dark:text-primary-400"
                >
                    <x-heroicon-o-cloud class="h-5 w-5" />
                </a>
            @else
                <span
                    title="{{ $file['name'] }} (link unavailable)"
                    class="inline-flex text-gray-400 dark:text-gray-500"
                >
                    <x-heroicon-o-cloud class="h-5 w-5" />
                </span>
            @endif
        @endforeach
    @endif
</span>
