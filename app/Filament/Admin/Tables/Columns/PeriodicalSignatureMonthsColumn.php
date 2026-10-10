<?php

namespace App\Filament\Admin\Tables\Columns;

use Filament\Support\Enums\Alignment;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Js;

class PeriodicalSignatureMonthsColumn extends TextInputColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->type('number')
            ->inputMode('numeric')
            ->step(1)
            ->rules(['nullable', 'numeric', 'min:0'])
            ->extraInputAttributes([
                'style' => 'width: 100px; min-width: 100px; max-width: 100px; text-align: center; flex: none;',
                'min' => 0,
            ], merge: true);
    }

    public function toEmbeddedHtml(): string
    {
        $isDisabled = $this->isDisabled();
        $state = max(0, (int) ($this->getState() ?? 0));
        $mask = $this->getMask();

        $alignment = $this->getAlignment() ?? Alignment::Start;

        if (! $alignment instanceof Alignment) {
            $alignment = filled($alignment) ? (Alignment::tryFrom($alignment) ?? $alignment) : null;
        }

        $type = filled($mask) ? 'text' : $this->getType();

        $xData = RawJs::make(<<<JS
{
    error: undefined,
    isLoading: false,
    state: {$this->js($state)},
    name: {$this->js($this->getName())},
    recordKey: {$this->js($this->getRecordKey())},
    isDisabled: {$this->js($isDisabled)},
    normalize(value) {
        const parsed = parseInt(value, 10)
        return Number.isNaN(parsed) ? 0 : Math.max(0, parsed)
    },
    increment() {
        if (this.isLoading || this.isDisabled) {
            return
        }
        this.state = this.normalize(this.state) + 1
        this.persist()
    },
    decrement() {
        if (this.isLoading || this.isDisabled) {
            return
        }
        this.state = Math.max(0, this.normalize(this.state) - 1)
        this.persist()
    },
    async persist() {
        if (this.isLoading || this.isDisabled) {
            return
        }
        this.isLoading = true
        const normalized = this.normalize(this.state)
        const response = await this.\$wire.updateTableColumnState(
            this.name,
            this.recordKey,
            normalized,
        )
        this.error = response?.error ?? undefined
        if (! this.error) {
            this.state = normalized
            if (this.\$refs.serverState) {
                this.\$refs.serverState.value = String(normalized)
            }
        }
        this.isLoading = false
    },
}
JS);

        $attributes = $this->getExtraAttributeBag()
            ->merge([
                'x-data' => $xData,
            ], escape: false)
            ->class([
                'fi-ta-text-input',
                'fi-inline' => $this->isInline(),
            ]);

        $inputAttributes = $this->getExtraInputAttributeBag()
            ->merge([
                'disabled' => $isDisabled,
                'wire:loading.attr' => 'disabled',
                'wire:target' => implode(',', Table::LOADING_TARGETS),
                'x-bind:disabled' => $isDisabled ? null : 'isLoading',
                'inputmode' => $this->getInputMode(),
                'placeholder' => $this->getPlaceholder(),
                'step' => $this->getStep(),
                'type' => $type,
                'x-mask'.($mask instanceof RawJs ? ':dynamic' : '') => filled($mask) ? $mask : null,
                'x-tooltip' => filled($tooltip = $this->getTooltip($state))
                    ? '{
                        content: '.Js::from($tooltip).',
                        theme: $store.theme,
                        allowHTML: '.Js::from($tooltip instanceof Htmlable).',
                    }'
                    : null,
            ], escape: false)
            ->class([
                'fi-input',
                ($alignment instanceof Alignment) ? "fi-align-{$alignment->value}" : (is_string($alignment) ? $alignment : ''),
            ]);

        ob_start(); ?>

        <div
            wire:ignore
            <?= $attributes->toHtml() ?>
        >
            <input type="hidden" value="<?= e($state) ?>" x-ref="serverState" />

            <div
                x-bind:class="{
                    'fi-disabled': isLoading || isDisabled,
                    'fi-invalid': error !== undefined,
                }"
                x-tooltip="
                    error === undefined
                        ? false
                        : {
                            content: error,
                            theme: $store.theme,
                        }
                "
                class="fi-input-wrp ss-months-stepper"
                style="width: fit-content;"
            >
                <div class="fi-input-wrp-prefix fi-input-wrp-prefix-has-content fi-inline fi-input-wrp-prefix-has-label ss-months-stepper__zone">
                    <button
                        type="button"
                        class="ss-months-stepper__btn"
                        aria-label="Decrease months"
                        x-bind:disabled="isLoading || isDisabled"
                        x-on:click.prevent.stop="decrement()"
                    >
                        −
                    </button>
                </div>

                <div
                    class="fi-input-wrp-content-ctn"
                    x-on:click.prevent.stop
                >
                    <input
                        x-model.number="state"
                        x-on:change="persist()"
                        <?= $inputAttributes->toHtml() ?>
                    />
                </div>

                <div class="fi-input-wrp-suffix fi-inline fi-input-wrp-suffix-has-label ss-months-stepper__zone">
                    <button
                        type="button"
                        class="ss-months-stepper__btn"
                        aria-label="Increase months"
                        x-bind:disabled="isLoading || isDisabled"
                        x-on:click.prevent.stop="increment()"
                    >
                        +
                    </button>
                </div>
            </div>
        </div>

        <?php return ob_get_clean();
    }

    private function js(mixed $value): string
    {
        return Js::from($value)->toHtml();
    }
}
