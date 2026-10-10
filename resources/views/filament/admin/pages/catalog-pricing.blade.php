<x-filament-panels::page>
    <div class="fi-catalog-pricing-wrap">
        <h2 class="fi-catalog-pricing-title">Pricing</h2>

        <div class="fi-catalog-pricing-scroll">
            <table class="fi-catalog-pricing">
                <thead>
                    <tr>
                        <th class="fi-catalog-pricing__name-col">Name</th>
                        <th>Base price</th>
                        <th>Customs</th>
                        <th>Agent</th>
                        <th>Other</th>
                        <th>Cost</th>
                        <th>Markup</th>
                        <th>Price</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->collections as $collection)
                        <tr
                            wire:key="pricing-{{ $collection->id }}"
                            x-data="catalogPricingRow({
                                id: {{ $collection->id }},
                                port: {{ $collection->port_price !== null ? (float) $collection->port_price : 'null' }},
                                agent: {{ $collection->agent_fee !== null ? (float) $collection->agent_fee : 'null' }},
                                other: {{ $collection->other_cost !== null ? (float) $collection->other_cost : 'null' }},
                                markup: {{ $collection->markup_percent !== null ? (float) $collection->markup_percent : 'null' }},
                                defaultAgent: {{ \App\Services\CollectionPricing::DEFAULT_AGENT_FEE }},
                                customsRate: {{ \App\Services\CollectionPricing::CUSTOMS_RATE }},
                            })"
                        >
                            <td class="fi-catalog-pricing__name-col">
                                <input
                                    type="text"
                                    class="fi-catalog-pricing__name"
                                    value="{{ $collection->name }}"
                                    wire:change="renameCollection({{ $collection->id }}, $event.target.value)"
                                >
                            </td>
                            <td>
                                <input
                                    type="text"
                                    inputmode="decimal"
                                    class="fi-catalog-pricing__cell"
                                    x-model="portDisplay"
                                    placeholder="—"
                                    @input="onPortInput($event.target.value)"
                                    @change="persist('port_price', $event.target.value)"
                                    @blur="formatPort()"
                                >
                            </td>
                            <td class="fi-catalog-pricing__readonly" x-text="customsDisplay"></td>
                            <td>
                                <input
                                    type="text"
                                    inputmode="decimal"
                                    class="fi-catalog-pricing__cell"
                                    :class="{ 'is-empty': agent === null }"
                                    x-model="agentDisplay"
                                    placeholder="—"
                                    @input="onAgentInput($event.target.value)"
                                    @change="persist('agent_fee', $event.target.value)"
                                    @blur="formatAgent()"
                                >
                            </td>
                            <td>
                                <input
                                    type="text"
                                    inputmode="decimal"
                                    class="fi-catalog-pricing__cell"
                                    :class="{ 'is-empty': other === null }"
                                    x-model="otherDisplay"
                                    placeholder="—"
                                    @input="onOtherInput($event.target.value)"
                                    @change="persist('other_cost', $event.target.value)"
                                    @blur="formatOther()"
                                >
                            </td>
                            <td class="fi-catalog-pricing__readonly" x-text="costDisplay"></td>
                            <td>
                                <input
                                    type="text"
                                    inputmode="decimal"
                                    class="fi-catalog-pricing__cell"
                                    :class="{ 'is-empty': markup === null }"
                                    x-model="markupDisplay"
                                    placeholder=""
                                    @input="onMarkupInput($event.target.value)"
                                    @change="persist('markup_percent', $event.target.value)"
                                    @blur="formatMarkup()"
                                >
                            </td>
                            <td class="fi-catalog-pricing__readonly" x-text="priceDisplay"></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="fi-catalog-pricing__empty">
                                No collections yet. Use <strong>+ Add</strong> to create one.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="fi-catalog-pricing-footer">
            <button
                type="button"
                class="fi-catalog-pricing-add"
                wire:click="mountAction('add')"
            >
                + Add
            </button>
        </div>
    </div>

    @script
    <script>
        Alpine.data('catalogPricingRow', (config) => ({
            port: config.port,
            agent: config.agent,
            other: config.other,
            markup: config.markup,
            defaultAgent: config.defaultAgent,
            customsRate: config.customsRate,
            portDisplay: '',
            agentDisplay: '',
            otherDisplay: '',
            markupDisplay: '',

            init() {
                this.formatPort()
                this.formatAgent()
                this.formatOther()
                this.formatMarkup()
            },

            parse(value) {
                let raw = String(value ?? '')
                    .replace(/\u00a0/g, '')
                    .replace(/\s/g, '')
                    .replace(/€/gi, '')
                    .replace(/eur/gi, '')
                    .replace(',', '.')
                    .trim()

                if (raw === '' || raw === '—' || raw === '-' || raw === '–') {
                    return null
                }

                const n = Number(raw)

                return Number.isFinite(n) && n >= 0 ? Math.round(n * 10000) / 10000 : null
            },

            formatEuro(amount) {
                if (amount === null || amount === undefined || Number.isNaN(amount)) {
                    return '—'
                }

                let formatted = Number(amount).toFixed(2).replace(/\.?0+$/, '')
                formatted = formatted.replace('.', ',')

                return `${formatted} €`
            },

            formatNumber(amount) {
                if (amount === null || amount === undefined || Number.isNaN(amount)) {
                    return ''
                }

                let formatted = Number(amount).toFixed(2).replace(/\.?0+$/, '')

                return formatted.replace('.', ',')
            },

            get customs() {
                if (this.port === null) {
                    return null
                }

                return Math.round(this.port * this.customsRate * 100) / 100
            },

            get cost() {
                if (this.port === null) {
                    return null
                }

                return Math.round((this.port + (this.customs ?? 0) + (this.agent ?? 0) + (this.other ?? 0)) * 100) / 100
            },

            get price() {
                if (this.cost === null || this.markup === null) {
                    return null
                }

                return Math.round(this.cost * (1 + this.markup / 100) * 100) / 100
            },

            get customsDisplay() {
                return this.formatEuro(this.customs)
            },

            get costDisplay() {
                return this.formatEuro(this.cost)
            },

            get priceDisplay() {
                return this.formatEuro(this.price)
            },

            onPortInput(value) {
                this.port = this.parse(value)

                if (this.port !== null && this.agent === null) {
                    this.agent = this.defaultAgent
                    this.formatAgent()
                }
            },

            onAgentInput(value) {
                this.agent = this.parse(value)
            },

            onOtherInput(value) {
                this.other = this.parse(value)
            },

            onMarkupInput(value) {
                this.markup = this.parse(value)
            },

            formatPort() {
                this.portDisplay = this.port === null ? '' : this.formatEuro(this.port)
            },

            formatAgent() {
                this.agentDisplay = this.agent === null ? '' : this.formatEuro(this.agent)
            },

            formatOther() {
                this.otherDisplay = this.other === null ? '' : this.formatEuro(this.other)
            },

            formatMarkup() {
                this.markupDisplay = this.formatNumber(this.markup)
            },

            persist(field, value) {
                this.$wire.saveField(config.id, field, value)
            },
        }))
    </script>
    @endscript
</x-filament-panels::page>
