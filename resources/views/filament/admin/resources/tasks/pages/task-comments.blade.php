<x-filament-panels::page>
    <div class="space-y-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <form wire:submit="createComment" class="space-y-4">
                {{ $this->form }}

                <div class="flex justify-end">
                    <x-filament::button type="submit">
                        Add comment
                    </x-filament::button>
                </div>
            </form>
        </div>

        <div class="space-y-3">
            @forelse ($this->getComments() as $comment)
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <div class="mb-2 flex items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                        <span>{{ $comment->user?->name ?? $comment->user?->email ?? 'Unknown user' }}</span>
                        <span>{{ $comment->created_at?->format('Y-m-d H:i:s') }}</span>
                    </div>

                    <div class="whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-200">
                        {{ $comment->content }}
                    </div>

                    @if (! empty($comment->attachments))
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($comment->attachments as $file)
                                <a
                                    href="{{ \Illuminate\Support\Facades\Storage::url($file) }}"
                                    target="_blank"
                                    class="rounded-md border border-gray-200 px-2 py-1 text-xs text-primary-700 hover:bg-gray-50 dark:border-gray-600 dark:text-primary-300 dark:hover:bg-gray-800"
                                >
                                    {{ basename((string) $file) }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                    No comments yet.
                </div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
