@php
    /** @var \App\Models\Document $record */
    $files = $record->customerPortalFileLinks();
@endphp

@if ($files === [])
    <span class="text-gray-400 dark:text-gray-500">—</span>
@else
    <span class="inline-flex flex-wrap items-center gap-2">
        @foreach ($files as $file)
            <a
                href="{{ $file['url'] }}"
                target="_blank"
                rel="noopener"
                title="{{ $file['name'] ?? 'Open file' }}"
                class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:text-primary-500 hover:underline dark:text-primary-400"
            >
                <x-heroicon-o-document-text class="h-5 w-5 shrink-0" />
                <span class="max-w-[14rem] truncate">{{ $file['name'] ?? 'Open' }}</span>
            </a>
        @endforeach
    </span>
@endif
