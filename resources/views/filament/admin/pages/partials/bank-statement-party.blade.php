@php
    /** @var \App\Models\BankStatementTransaction $transaction */
    /** @var array{name: ?string, iban: ?string, is_counterpart: bool} $party */
@endphp

@if ($party['is_counterpart'])
    <div
        wire:key="bs-counterpart-{{ $transaction->getKey() }}"
        class="bs-inline-edit"
        x-data="{
            editing: false,
            saving: false,
            value: @js($transaction->counterpart_name),
            display: @js($party['name']),
            draft: '',
            start() {
                this.draft = this.value ?? this.display ?? '';
                this.editing = true;
                this.$nextTick(() => this.$refs.input?.focus());
            },
            cancel() {
                this.editing = false;
                this.saving = false;
            },
            async save() {
                const next = (this.draft ?? '').trim();
                const current = (this.value ?? '').trim();
                if (next === current) {
                    this.editing = false;
                    return;
                }
                this.saving = true;
                try {
                    await $wire.updateCounterpart({{ $transaction->getKey() }}, next === '' ? null : next);
                    this.value = next === '' ? null : next;
                    this.display = next === '' ? null : next;
                    this.editing = false;
                } finally {
                    this.saving = false;
                }
            }
        }"
    >
        <button
            type="button"
            x-show="! editing"
            @click="start()"
            class="bs-inline-edit__trigger"
            title="Edit counterpart"
        >
            <span x-show="display" x-text="display" class="bs-inline-edit__text" :title="display || ''"></span>
            <span x-show="! display" class="bs-inline-edit__placeholder">Add counterpart</span>
            @if (filled($party['iban']))
                <span class="bs-inline-edit__meta" title="{{ $party['iban'] }}">{{ $party['iban'] }}</span>
            @endif
        </button>
        <div x-show="editing" x-cloak class="bs-inline-edit__editor">
            <input
                type="text"
                x-ref="input"
                x-model="draft"
                maxlength="255"
                class="bs-inline-edit__input"
                @keydown.enter.prevent="save()"
                @keydown.escape.prevent="cancel()"
            />
            <div class="bs-inline-edit__actions">
                <button type="button" class="bs-inline-edit__save" @click="save()" :disabled="saving">Save</button>
                <button type="button" class="bs-inline-edit__cancel" @click="cancel()" :disabled="saving">Cancel</button>
            </div>
        </div>
    </div>
@else
    <div class="bs-party">
        @if (filled($party['name']))
            <div class="bs-inline-edit__text" title="{{ $party['name'] }}">{{ $party['name'] }}</div>
        @endif
        @if (filled($party['iban']))
            <div class="bs-inline-edit__meta" title="{{ $party['iban'] }}">{{ $party['iban'] }}</div>
        @elseif (! filled($party['name']))
            —
        @endif
    </div>
@endif
