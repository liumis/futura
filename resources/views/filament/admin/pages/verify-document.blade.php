<x-filament-panels::page>
    <form wire:submit="verify" class="space-y-6 max-w-2xl">
        {{ $this->form }}

        <div class="flex gap-3">
            <x-filament::button type="submit" icon="heroicon-o-shield-check">
                Verify
            </x-filament::button>
        </div>
    </form>

    @if ($result)
        <div class="mt-8 max-w-3xl space-y-4">
            <div @class([
                'rounded-xl border p-4',
                'border-success-600/40 bg-success-50 dark:bg-success-950/30' => $result['valid'],
                'border-danger-600/40 bg-danger-50 dark:bg-danger-950/30' => ! $result['valid'],
            ])>
                <div class="text-sm font-semibold">
                    {{ $result['valid'] ? 'Valid — hash matched' : 'Not valid — no matching hash' }}
                </div>
                <div class="mt-2 break-all font-mono text-xs text-gray-700 dark:text-gray-300">
                    SHA256: {{ $result['hash'] }}
                </div>
            </div>

            @if ($result['valid'])
                <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left dark:bg-gray-800">
                            <tr>
                                <th class="px-3 py-2 font-medium">Document</th>
                                <th class="px-3 py-2 font-medium">Type</th>
                                <th class="px-3 py-2 font-medium">Approved</th>
                                <th class="px-3 py-2 font-medium">Matched</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($result['matches'] as $match)
                                <tr class="border-t border-gray-200 dark:border-gray-700">
                                    <td class="px-3 py-2">
                                        <a href="{{ $match['edit_url'] }}" class="text-primary-600 underline">
                                            {{ $match['name'] }}
                                        </a>
                                        <div class="text-xs text-gray-500">{{ $match['document_date'] ?? '—' }}</div>
                                    </td>
                                    <td class="px-3 py-2">{{ $match['type'] }}</td>
                                    <td class="px-3 py-2">
                                        {{ $match['approved_by'] }}
                                        <div class="text-xs text-gray-500">{{ $match['approval_date'] ?? '—' }}</div>
                                    </td>
                                    <td class="px-3 py-2 font-mono text-xs">{{ $match['matched_as'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
