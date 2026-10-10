<div class="space-y-3">
    @if (filled($url ?? null))
        <iframe
            src="{{ $url }}"
            title="PDF preview"
            class="h-[70vh] w-full rounded-lg border border-gray-200 bg-white dark:border-gray-700"
        ></iframe>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            <a
                href="{{ $url }}"
                target="_blank"
                rel="noopener noreferrer"
                class="font-medium text-primary-600 hover:underline dark:text-primary-400"
            >
                Open in a new tab
            </a>
        </p>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">No PDF preview is available yet.</p>
    @endif
</div>
