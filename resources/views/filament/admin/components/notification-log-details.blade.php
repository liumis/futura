@php
    /** @var \App\Models\NotificationLog $record */
@endphp

<div class="space-y-4 text-sm text-gray-700 dark:text-gray-200">
    <div>
        <p class="font-medium text-gray-950 dark:text-white">Sent at</p>
        <p>{{ $record->created_at?->format('Y-m-d H:i:s') ?? '—' }}</p>
    </div>

    <div>
        <p class="font-medium text-gray-950 dark:text-white">Recipient</p>
        <p>{{ $record->recipient?->fullName() ?: ($record->recipient?->email ?? '—') }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <p class="font-medium text-gray-950 dark:text-white">Channel</p>
            <p>{{ $record->channelLabel() }}</p>
        </div>
        <div>
            <p class="font-medium text-gray-950 dark:text-white">Type</p>
            <p>{{ $record->typeLabel() }}</p>
        </div>
    </div>

    <div>
        <p class="font-medium text-gray-950 dark:text-white">Title</p>
        <p>{{ $record->title }}</p>
    </div>

    <div>
        <p class="font-medium text-gray-950 dark:text-white">Body</p>
        <p class="whitespace-pre-wrap">{{ $record->body }}</p>
    </div>

    @if (filled($record->url))
        <div>
            <p class="font-medium text-gray-950 dark:text-white">Link</p>
            <p><a href="{{ $record->url }}" target="_blank" rel="noopener" class="text-primary-600 hover:underline dark:text-primary-400">{{ $record->url }}</a></p>
        </div>
    @endif
</div>
