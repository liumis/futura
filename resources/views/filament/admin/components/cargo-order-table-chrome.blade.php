<div class="fi-cargo-order-chrome" wire:ignore data-cargo-order-chrome="1">
    <div class="fi-cargo-order-toolbar">
        <input
            type="search"
            data-cargo-order-search
            placeholder="Search collections, colors, codes..."
            class="fi-cargo-order-search"
            autocomplete="off"
        />

        <div class="fi-cargo-order-toolbar-end">
            <div class="fi-cargo-order-filters" role="group" aria-label="Show items">
                <button type="button" data-cargo-order-filter="all">All</button>
                <button type="button" data-cargo-order-filter="ordered">Ordered</button>
                <button type="button" data-cargo-order-filter="unordered">Unordered</button>
            </div>

            <div class="fi-cargo-order-collapse-actions">
                <button type="button" data-cargo-order-expand>Expand all</button>
                <button type="button" data-cargo-order-collapse>Collapse all</button>
            </div>

            <div class="fi-cargo-order-view-switch" role="group" aria-label="Product layout">
                <button
                    type="button"
                    title="List view"
                    aria-label="List view"
                    data-cargo-order-view="list"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M2 4.75A.75.75 0 0 1 2.75 4h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 4.75ZM2 10a.75.75 0 0 1 .75-.75h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 10Zm0 5.25a.75.75 0 0 1 .75-.75h14.5a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
                    </svg>
                </button>
                <button
                    type="button"
                    title="Grid view"
                    aria-label="Grid view"
                    data-cargo-order-view="grid"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M4.25 2A2.25 2.25 0 0 0 2 4.25v2.5A2.25 2.25 0 0 0 4.25 9h2.5A2.25 2.25 0 0 0 9 6.75v-2.5A2.25 2.25 0 0 0 6.75 2h-2.5Zm0 9A2.25 2.25 0 0 0 2 13.25v2.5A2.25 2.25 0 0 0 4.25 18h2.5A2.25 2.25 0 0 0 9 15.75v-2.5A2.25 2.25 0 0 0 6.75 11h-2.5Zm9-9A2.25 2.25 0 0 0 11 4.25v2.5A2.25 2.25 0 0 0 13.25 9h2.5A2.25 2.25 0 0 0 18 6.75v-2.5A2.25 2.25 0 0 0 15.75 2h-2.5Zm0 9A2.25 2.25 0 0 0 11 13.25v2.5A2.25 2.25 0 0 0 13.25 18h2.5A2.25 2.25 0 0 0 18 15.75v-2.5A2.25 2.25 0 0 0 15.75 11h-2.5Z" clip-rule="evenodd" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <div class="fi-cargo-order-head" data-cargo-order-head aria-hidden="true">
        @foreach (($headings ?? ['', 'Color / product', 'Code', 'Stock', 'Amount / cost']) as $heading)
            <div>{{ $heading }}</div>
        @endforeach
    </div>

    <div class="fi-cargo-order-empty" data-cargo-order-empty hidden role="status">
        No products match this search or filter. Clear search, choose <strong>All</strong>, or expand collections.
    </div>

    <div
        class="fi-cargo-order-rec-popover"
        data-cargo-order-rec-popover
        hidden
        role="dialog"
        aria-label="Edit recommended stock"
    >
        <div class="fi-cargo-order-rec-popover__title">Recommended stock</div>
        <div class="fi-cargo-order-rec-popover__row">
            <input
                type="number"
                min="0"
                step="1"
                inputmode="numeric"
                data-cargo-order-rec-input
            />
            <span>m</span>
        </div>
        <div class="fi-cargo-order-rec-popover__actions">
            <button type="button" class="fi-cargo-order-rec-popover__save" data-cargo-order-rec-save>Save</button>
            <button type="button" class="fi-cargo-order-rec-popover__cancel" data-cargo-order-rec-cancel>Cancel</button>
        </div>
    </div>
</div>
