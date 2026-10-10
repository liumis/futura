<x-filament-panels::page>
    <div class="fi-customer-price-matrix-wrap">
        <p class="fi-customer-price-matrix-help">
            Rows are customer groups. Columns are collections. Empty cells (—) use the collection default price; a number overrides it for that group (€/m).
        </p>

        <div class="fi-customer-price-matrix-scroll">
            <table class="fi-customer-price-matrix">
                <thead>
                    <tr>
                        <th class="fi-customer-price-matrix__sticky">Customer group</th>
                        @foreach ($this->collections as $collection)
                            <th title="Default {{ \App\Support\Money::format((float) $collection->price) }}/m">
                                {{ $collection->name }}
                            </th>
                        @endforeach
                        <th class="fi-customer-price-matrix__actions"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->levels as $level)
                        <tr wire:key="level-{{ $level->id }}">
                            <td class="fi-customer-price-matrix__sticky">
                                <input
                                    type="text"
                                    class="fi-customer-price-matrix__name"
                                    value="{{ $level->name }}"
                                    wire:change="renameLevel({{ $level->id }}, $event.target.value)"
                                >
                            </td>
                            @foreach ($this->collections as $collection)
                                @php($price = $this->priceFor($level, $collection->id))
                                <td>
                                    <input
                                        type="text"
                                        inputmode="decimal"
                                        class="fi-customer-price-matrix__cell {{ $price === null ? 'is-empty' : '' }}"
                                        value="{{ $price ?? '' }}"
                                        placeholder="—"
                                        wire:change="savePrice({{ $level->id }}, {{ $collection->id }}, $event.target.value)"
                                    >
                                </td>
                            @endforeach
                            <td class="fi-customer-price-matrix__actions">
                                <button
                                    type="button"
                                    class="fi-customer-price-matrix__delete"
                                    wire:click="deleteLevel({{ $level->id }})"
                                    wire:confirm="Delete this customer group and its prices?"
                                    title="Delete group"
                                >
                                    ×
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $this->collections->count() + 2 }}" class="fi-customer-price-matrix__empty">
                                No customer groups yet. Use <strong>Add group</strong> to create one.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
