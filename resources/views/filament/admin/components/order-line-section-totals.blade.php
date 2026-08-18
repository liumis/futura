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
                    const input = section.querySelector('[data-order-amount-input="' + productId + '"]');

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

                if (! target?.matches?.('[data-order-amount-input]')) {
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
    </script>
</div>
