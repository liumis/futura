<div wire:ignore hidden aria-hidden="true">
    <script>
        (function () {
            if (window.__fiOrderSectionTotalsInitialized) {
                window.__fiOrderSectionTotalsUpdateAll?.();

                return;
            }

            window.__fiOrderSectionTotalsInitialized = true;

            function updateSection(section) {
                if (! section) {
                    return;
                }

                let productIds = [];

                try {
                    productIds = JSON.parse(section.dataset.fiOrderProductIds || '[]');
                } catch (error) {
                    return;
                }

                let total = 0;

                for (const productId of productIds) {
                    const input = section.querySelector(
                        '[data-order-amount-input="' + productId + '"], [data-cargo-product-amount="' + productId + '"]',
                    );

                    if (! input) {
                        continue;
                    }

                    const amount = parseInt(input.value || '0', 10);

                    if (Number.isFinite(amount) && amount > 0) {
                        total += amount;
                    }
                }

                const badge = section.querySelector('.fi-order-section-total');

                if (! badge) {
                    return;
                }

                badge.textContent = '(' + total + ')';
                badge.classList.toggle('fi-order-section-total--active', total > 0);
            }

            function updateAll(root = document) {
                root.querySelectorAll('[data-fi-order-section]').forEach(updateSection);
            }

            window.__fiOrderSectionTotalsUpdateAll = updateAll;

            function handleAmountChange(event) {
                const target = event.target;

                if (! target?.matches?.('[data-order-amount-input], [data-cargo-product-amount]')) {
                    return;
                }

                updateSection(target.closest('[data-fi-order-section]'));
            }

            document.addEventListener('input', handleAmountChange, true);
            document.addEventListener('change', handleAmountChange, true);

            updateAll();

            document.addEventListener('livewire:navigated', () => updateAll());

            if (typeof Livewire !== 'undefined') {
                Livewire.hook('commit', ({ succeed }) => {
                    succeed(() => {
                        queueMicrotask(() => updateAll());
                    });
                });
            }
        })();

        (function () {
            if (window.__fiSectionCollapseGuardInitialized) {
                return;
            }

            window.__fiSectionCollapseGuardInitialized = true;

            // Filament select dropdowns close on mousedown. The following click can
            // land on the collection header and collapse the open section.
            let ignoreHeaderClickUntil = 0;
            const dropdownSelector = [
                '[role="combobox"]',
                '[role="listbox"]',
                '[role="option"]',
                '.fi-dropdown-panel',
                '.fi-select-input',
                '.fi-fo-select',
            ].join(', ');

            document.addEventListener('mousedown', function (event) {
                if (event.target?.closest?.(dropdownSelector)) {
                    ignoreHeaderClickUntil = Date.now() + 500;
                }
            }, true);

            document.addEventListener('click', function (event) {
                if (Date.now() > ignoreHeaderClickUntil) {
                    return;
                }

                if (! event.target?.closest?.('.fi-section-header')) {
                    return;
                }

                event.stopImmediatePropagation();
                event.stopPropagation();
            }, true);
        })();
    </script>
</div>
