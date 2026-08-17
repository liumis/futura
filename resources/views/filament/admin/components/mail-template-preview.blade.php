<div class="space-y-4 text-sm text-gray-700 dark:text-gray-200">
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-[7rem_1fr]">
        <div class="font-medium text-gray-500 dark:text-gray-400">Subject</div>
        <div class="text-gray-950 dark:text-white">{{ $subject !== '' ? $subject : '—' }}</div>

        <div class="font-medium text-gray-500 dark:text-gray-400">From</div>
        <div class="text-gray-950 dark:text-white">{{ $fromName }}</div>
    </div>

    <div>
        <div class="mb-1 font-medium text-gray-500 dark:text-gray-400">Body</div>
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 whitespace-pre-wrap dark:border-gray-700 dark:bg-gray-900">{{ $body !== '' ? $body : '—' }}</div>
    </div>

    <p class="text-xs text-gray-400 dark:text-gray-500">
        This is a preview. When sending a warehouse order, the ordered items are appended below the body and
        <code>{order_id}</code> in the subject is replaced with the actual order number.
    </p>
</div>
