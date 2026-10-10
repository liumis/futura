@php
    /** @var \App\Models\Document $record */
    $files = $record->attachedFileLinks();
@endphp

@if ($files === [])
    <span class="text-gray-400 dark:text-gray-500">—</span>
@else
    <span class="inline-flex flex-wrap items-center gap-1">
        @foreach ($files as $file)
            @if (filled($file['url'] ?? null))
                <a
                    href="{{ $file['url'] }}"
                    target="_blank"
                    rel="noopener"
                    title="{{ $file['name'] ?? 'Open file' }}"
                    class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:text-primary-500 hover:underline dark:text-primary-400"
                >
                    <x-heroicon-o-document-text class="h-5 w-5 shrink-0" />
                    <span class="max-w-[10rem] truncate">{{ $file['name'] ?? 'Open' }}</span>
                </a>
            @else
                <span
                    title="{{ ($file['name'] ?? 'File').' (link unavailable)' }}"
                    class="inline-flex items-center gap-1 text-sm text-gray-400 dark:text-gray-500"
                >
                    <x-heroicon-o-document-text class="h-5 w-5 shrink-0" />
                    <span class="max-w-[10rem] truncate">{{ $file['name'] ?? 'File' }}</span>
                </span>
            @endif
        @endforeach
    </span>
@endif
