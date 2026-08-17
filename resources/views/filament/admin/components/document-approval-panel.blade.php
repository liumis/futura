@php
    /** @var \App\Models\Document|null $record */
    $record = $record ?? null;
@endphp

@if ($record === null)
    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
        Save the document first to see approval details.
    </div>
@elseif ($record->isApproved())
    @php
        $approverName = $record->approvedBy?->fullName() ?: '—';
        $approvedUrl = $record->displayFileUrl();
        $contentHash = (string) ($record->content_hash ?: '');
        $pdfHash = (string) ($record->pdf_hash ?: '');
    @endphp
    <div class="overflow-hidden rounded-xl border border-emerald-200 bg-emerald-50/60 dark:border-emerald-900/60 dark:bg-emerald-950/30">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-emerald-200/80 px-4 py-3 dark:border-emerald-900/50">
            <div class="flex items-center gap-2">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-900/60 dark:text-emerald-300">
                    <x-heroicon-o-lock-closed class="h-4 w-4" />
                </span>
                <div>
                    <p class="text-sm font-semibold text-emerald-900 dark:text-emerald-100">Approved & locked</p>
                    <p class="text-xs text-emerald-700/80 dark:text-emerald-300/80">This document can no longer be edited</p>
                </div>
            </div>
            @if (filled($approvedUrl))
                <a
                    href="{{ $approvedUrl }}"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-emerald-800 shadow-sm ring-1 ring-emerald-200 transition hover:bg-emerald-50 dark:bg-emerald-950 dark:text-emerald-200 dark:ring-emerald-800 dark:hover:bg-emerald-900"
                >
                    <x-heroicon-o-cloud class="h-4 w-4" />
                    Open in SharePoint
                </a>
            @endif
        </div>

        <div class="grid gap-4 px-4 py-4 sm:grid-cols-3">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-emerald-800/70 dark:text-emerald-300/70">Approved by</p>
                <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $approverName }}</p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-emerald-800/70 dark:text-emerald-300/70">Approval date</p>
                <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ optional($record->approval_date)->format('Y-m-d H:i:s') ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-emerald-800/70 dark:text-emerald-300/70">Confirmed IP</p>
                <p class="mt-1 font-mono text-sm text-gray-900 dark:text-white">{{ $record->confirmed_ip ?: '—' }}</p>
            </div>
        </div>

        @if ($contentHash !== '' || $pdfHash !== '')
            <div class="space-y-3 border-t border-emerald-200/80 bg-white/50 px-4 py-4 dark:border-emerald-900/50 dark:bg-black/10">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Integrity hashes</p>

                @if ($contentHash !== '')
                    <div x-data="{ copied: false }">
                        <div class="mb-1 flex items-center justify-between gap-2">
                            <p class="text-xs font-medium text-gray-600 dark:text-gray-300">Content SHA256</p>
                            <button
                                type="button"
                                class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                                x-on:click="navigator.clipboard.writeText(@js($contentHash)); copied = true; setTimeout(() => copied = false, 1500)"
                            >
                                <span x-show="!copied">Copy</span>
                                <span x-cloak x-show="copied">Copied</span>
                            </button>
                        </div>
                        <p class="truncate rounded-lg bg-gray-100 px-3 py-2 font-mono text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300" title="{{ $contentHash }}">
                            {{ $contentHash }}
                        </p>
                    </div>
                @endif

                @if ($pdfHash !== '')
                    <div x-data="{ copied: false }">
                        <div class="mb-1 flex items-center justify-between gap-2">
                            <p class="text-xs font-medium text-gray-600 dark:text-gray-300">PDF SHA256</p>
                            <button
                                type="button"
                                class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                                x-on:click="navigator.clipboard.writeText(@js($pdfHash)); copied = true; setTimeout(() => copied = false, 1500)"
                            >
                                <span x-show="!copied">Copy</span>
                                <span x-cloak x-show="copied">Copied</span>
                            </button>
                        </div>
                        <p class="truncate rounded-lg bg-gray-100 px-3 py-2 font-mono text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300" title="{{ $pdfHash }}">
                            {{ $pdfHash }}
                        </p>
                    </div>
                @endif
            </div>
        @endif
    </div>
@elseif ($record->pendingApproverLabels() !== [])
    @php
        $pending = $record->pendingApproverLabels();
        $status = $record->statusLabel();
    @endphp
    <div class="overflow-hidden rounded-xl border border-amber-200 bg-amber-50/70 dark:border-amber-900/50 dark:bg-amber-950/25">
        <div class="flex items-start gap-3 px-4 py-4">
            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300">
                <x-heroicon-o-clock class="h-4 w-4" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-amber-950 dark:text-amber-100">{{ $status }}</p>
                <p class="mt-0.5 text-xs text-amber-800/80 dark:text-amber-300/80">Pending from</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($pending as $name)
                        <span class="inline-flex items-center rounded-full bg-white px-2.5 py-1 text-xs font-medium text-amber-900 ring-1 ring-amber-200 dark:bg-amber-950 dark:text-amber-100 dark:ring-amber-800">
                            {{ $name }}
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@elseif ($record->awaitsDokobitSignature())
    <div class="overflow-hidden rounded-xl border border-sky-200 bg-sky-50/70 dark:border-sky-900/50 dark:bg-sky-950/25">
        <div class="flex items-start gap-3 px-4 py-4">
            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sky-700 dark:bg-sky-900/50 dark:text-sky-300">
                <x-heroicon-o-pencil-square class="h-4 w-4" />
            </span>
            <div>
                <p class="text-sm font-semibold text-sky-950 dark:text-sky-100">Awaiting Dokobit signatures</p>
                <p class="mt-1 text-sm text-sky-800/90 dark:text-sky-200/90">
                    Use <strong>Approvals &amp; Sign</strong> to open Dokobit. External invitees can sign from their email link.
                </p>
            </div>
        </div>
    </div>
@else
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/40">
        <div class="flex items-start gap-3 px-4 py-4">
            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-200 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                <x-heroicon-o-document-check class="h-4 w-4" />
            </span>
            <div>
                <p class="text-sm font-semibold text-gray-900 dark:text-white">Not approved yet</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    Use <strong>Approve</strong> for a quick lock, or <strong>Approvals &amp; Sign</strong> to assign internal approvers and Dokobit signers (including external email invitees).
                </p>
            </div>
        </div>
    </div>
@endif
