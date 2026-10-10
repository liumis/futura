<div
    class="fi-cargo-order-cart"
    wire:key="cargo-order-cart"
    x-data="{ open: @entangle('orderCartOpen') }"
    x-cloak
>
    <button
        type="button"
        class="fi-cargo-order-cart__fab"
        wire:click="toggleOrderCart"
        title="Order cart"
    >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="fi-cargo-order-cart__fab-icon" aria-hidden="true">
            <path d="M2.25 2.25a.75.75 0 0 0 0 1.5h1.386c.17 0 .318.114.362.278l2.558 9.592a2.25 2.25 0 0 0 2.183 1.68h7.962a.75.75 0 0 0 0-1.5H8.739a.75.75 0 0 1-.728-.56l-.272-1.02h9.011a2.25 2.25 0 0 0 2.183-1.68l1.348-5.056A.75.75 0 0 0 19.81 5.25H6.197l-.5-1.876A2.25 2.25 0 0 0 3.636 1.5H2.25Z" />
            <path d="M8.25 19.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0ZM18.75 19.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z" />
        </svg>
        @if ($this->orderCartCount() > 0)
            <span class="fi-cargo-order-cart__badge" data-cargo-cart-badge>{{ $this->orderCartCount() }}</span>
        @else
            <span class="fi-cargo-order-cart__badge" data-cargo-cart-badge hidden>0</span>
        @endif
    </button>

    <div
        class="fi-cargo-order-cart__panel"
        x-show="open"
        x-transition.origin.bottom.right
        style="display: none;"
    >
        <div class="fi-cargo-order-cart__header">
            <div>
                <div class="fi-cargo-order-cart__title">Order cart</div>
                <div class="fi-cargo-order-cart__subtitle">
                    <span data-cargo-cart-count>{{ $this->orderCartCount() }}</span> item(s) on this warehouse order
                </div>
            </div>
            <button type="button" class="fi-cargo-order-cart__close" wire:click="toggleOrderCart" title="Close">×</button>
        </div>

        <div class="fi-cargo-order-cart__body" data-cargo-cart-body>
            @forelse ($this->orderCartItems as $item)
                <div class="fi-cargo-order-cart__row" wire:key="cart-item-{{ $item['product_id'] }}-{{ $item['amount'] }}">
                    <div class="fi-cargo-order-cart__meta">
                        <div class="fi-cargo-order-cart__label">{{ $item['label'] }}</div>
                        <div class="fi-cargo-order-cart__code">{{ $item['code'] }}</div>
                    </div>
                    <div class="fi-cargo-order-cart__controls">
                        <input
                            type="number"
                            min="0"
                            step="1"
                            class="fi-cargo-order-cart__qty"
                            value="{{ $item['amount'] }}"
                            wire:change="updateOrderCartAmount({{ $item['product_id'] }}, $event.target.value)"
                        >
                        <button
                            type="button"
                            class="fi-cargo-order-cart__remove"
                            wire:click="removeOrderCartItem({{ $item['product_id'] }})"
                            title="Remove"
                        >×</button>
                    </div>
                </div>
            @empty
                <div class="fi-cargo-order-cart__empty" data-cargo-cart-empty>
                    Cart is empty. Use Quick order or set quantities in the product list.
                </div>
            @endforelse
        </div>

        <div class="fi-cargo-order-cart__footer">
            <div class="fi-cargo-order-cart__footer-start">
                <button
                    type="button"
                    class="fi-btn fi-size-sm fi-btn-color-gray fi-cargo-order-cart__expand"
                    wire:click="mountAction('expandOrderCart')"
                    @if ($this->orderCartCount() === 0) disabled @endif
                >
                    Expand
                </button>
                <x-filament::button
                    color="gray"
                    size="sm"
                    wire:click="mountAction('quickOrder')"
                >
                    Edit quick order
                </x-filament::button>
            </div>
            <button
                type="button"
                class="fi-cargo-order-cart__clear"
                data-cargo-cart-clear
                @if ($this->orderCartCount() === 0) hidden @endif
                wire:click="clearOrderCart"
                wire:confirm="Clear all quantities on this order?"
            >
                Clear
            </button>
        </div>
    </div>

    @script
    <script>
        const syncCargoAmountInputs = (productId, amount, { silent = false } = {}) => {
            const value = String(amount ?? 0)

            document.querySelectorAll(`[data-cargo-product-amount="${productId}"]`).forEach((input) => {
                const row = input.closest('.fi-cargo-order-row')
                if (row) {
                    row.classList.toggle('fi-cargo-order-row--ordered', parseInt(value || '0', 10) > 0)
                }

                if (input.value === value) {
                    return
                }

                input.value = value

                if (! silent) {
                    input.dispatchEvent(new Event('input', { bubbles: true }))
                    input.dispatchEvent(new Event('change', { bubbles: true }))
                }
            })
        }

        const refreshCargoCartChrome = () => {
            let count = 0
            document.querySelectorAll('[data-cargo-product-amount]').forEach((input) => {
                if ((parseInt(input.value || '0', 10) || 0) > 0) {
                    count++
                }
            })

            document.querySelectorAll('[data-cargo-cart-badge]').forEach((badge) => {
                badge.textContent = String(count)
                badge.hidden = count <= 0
            })
            document.querySelectorAll('[data-cargo-cart-count]').forEach((el) => {
                el.textContent = String(count)
            })
            document.querySelectorAll('[data-cargo-cart-clear]').forEach((el) => {
                el.hidden = count <= 0
            })
        }

        const applyCargoAmountBatch = (items) => {
            ;(items || []).forEach((item) => {
                const productId = item.productId ?? item.product_id
                const amount = item.amount
                if (! productId) {
                    return
                }
                syncCargoAmountInputs(productId, amount, { silent: true })
            })

            refreshCargoCartChrome()
            window.dispatchEvent(new CustomEvent('cargo-cost-recalculate'))
            window.__cargoOrderPicker?.applyFilters?.()
        }

        $wire.on('cargo-amount-synced', (payload) => {
            const detail = Array.isArray(payload) ? payload[0] : payload
            if (! detail?.productId) {
                return
            }

            syncCargoAmountInputs(detail.productId, detail.amount, { silent: true })
            refreshCargoCartChrome()
            window.dispatchEvent(new CustomEvent('cargo-cost-recalculate'))
            window.__cargoOrderPicker?.applyFilters?.()
        })

        $wire.on('cargo-amounts-batch-synced', (payload) => {
            const detail = Array.isArray(payload) ? payload[0] : payload
            applyCargoAmountBatch(detail?.items ?? detail ?? [])
        })
    </script>
    @endscript
</div>
