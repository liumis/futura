<div class="space-y-4 text-sm text-gray-700 dark:text-gray-200">
    <div class="grid gap-3 sm:grid-cols-2">
        <div>
            <p class="font-medium text-gray-500 dark:text-gray-400">Sent at</p>
            <p>{{ $record->created_at?->format('Y-m-d H:i:s') ?? '—' }}</p>
        </div>

        <div>
            <p class="font-medium text-gray-500 dark:text-gray-400">Sent by</p>
            <p>{{ $record->user?->name ?? '—' }}</p>
        </div>

        <div>
            <p class="font-medium text-gray-500 dark:text-gray-400">Mailer</p>
            <p>{{ $record->mailer ?? '—' }}</p>
        </div>

        <div>
            <p class="font-medium text-gray-500 dark:text-gray-400">From</p>
            <p>{{ \App\Models\EmailLog::formatAddressList($record->from) }}</p>
        </div>

        <div class="sm:col-span-2">
            <p class="font-medium text-gray-500 dark:text-gray-400">To</p>
            <p>{{ \App\Models\EmailLog::formatAddressList($record->to) }}</p>
        </div>

        @if (filled($record->cc))
            <div class="sm:col-span-2">
                <p class="font-medium text-gray-500 dark:text-gray-400">Cc</p>
                <p>{{ \App\Models\EmailLog::formatAddressList($record->cc) }}</p>
            </div>
        @endif

        @if (filled($record->bcc))
            <div class="sm:col-span-2">
                <p class="font-medium text-gray-500 dark:text-gray-400">Bcc</p>
                <p>{{ \App\Models\EmailLog::formatAddressList($record->bcc) }}</p>
            </div>
        @endif

        <div class="sm:col-span-2">
            <p class="font-medium text-gray-500 dark:text-gray-400">Subject</p>
            <p>{{ $record->subject ?? '—' }}</p>
        </div>
    </div>

    <div>
        <p class="mb-2 font-medium text-gray-500 dark:text-gray-400">Body</p>
        <div class="max-h-96 overflow-y-auto rounded-lg border border-gray-200 bg-gray-50 p-4 whitespace-pre-wrap dark:border-gray-700 dark:bg-gray-900">
            {{ $record->body ?: '—' }}
        </div>
    </div>
</div>
