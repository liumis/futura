(() => {
    const STORAGE = {
        view: 'fi-cargo-order-view',
        filter: 'fi-cargo-order-filter',
        search: 'fi-cargo-order-search',
        collapsed: 'fi-cargo-order-collapsed',
    };

    const stateFor = (chrome) => {
        if (! chrome.__fiCargoOrderState) {
            chrome.__fiCargoOrderState = {
                filter: 'all',
                search: '',
                view: 'list',
                collapsed: {},
                recEditor: { open: false, colorId: 0, value: 0, top: 0, left: 0 },
            };
        }

        return chrome.__fiCargoOrderState;
    };

    const wrapFor = (chrome) => chrome.closest('.fi-cargo-order-wrap') || chrome.parentElement;

    const readStorage = (chrome) => {
        const state = stateFor(chrome);

        try {
            const view = localStorage.getItem(STORAGE.view);
            if (view === 'grid' || view === 'list') {
                state.view = view;
            }
            // Prefer All on create so a leftover Ordered filter cannot empty the catalog.
            const filter = localStorage.getItem(STORAGE.filter);
            if (filter === 'all' || filter === 'ordered' || filter === 'unordered') {
                state.filter = filter;
            }
            // Do not restore search — a leftover query makes the catalog look empty.
            state.search = '';
            try {
                localStorage.removeItem(STORAGE.search);
            } catch (error) {}

            const collapsed = JSON.parse(localStorage.getItem(STORAGE.collapsed) || '{}');
            if (collapsed && typeof collapsed === 'object') {
                state.collapsed = collapsed;
            }
        } catch (error) {}
    };

    const defaultCollapseAllIfUnset = (chrome) => {
        const state = stateFor(chrome);
        const wrap = wrapFor(chrome);
        if (! wrap) {
            return;
        }

        const keys = [...wrap.querySelectorAll('[data-collection-key]')]
            .map((el) => el.dataset.collectionKey)
            .filter(Boolean);

        if (keys.length === 0) {
            return;
        }

        // First visit (or cleared storage): start collapsed so large catalogs are usable.
        if (Object.keys(state.collapsed).length === 0) {
            const next = {};
            keys.forEach((key) => {
                next[key] = true;
            });
            state.collapsed = next;
            persistCollapsed(state);
        }
    };

    const persistCollapsed = (state) => {
        try {
            localStorage.setItem(STORAGE.collapsed, JSON.stringify(state.collapsed));
        } catch (error) {}
    };

    const rowAmount = (el) => {
        const input = el.querySelector('[data-cargo-product-amount], [data-order-amount-input]');

        return parseInt((input && input.value) || '0', 10) || 0;
    };

    const rowVisible = (state, el) => {
        const q = (state.search || '').trim().toLowerCase();
        const haystack = (el.dataset.search || el.getAttribute('data-search') || '').toLowerCase();
        if (q && ! haystack.includes(q)) {
            return false;
        }
        const v = rowAmount(el);
        if (state.filter === 'all') {
            return true;
        }
        if (state.filter === 'ordered') {
            return v > 0;
        }

        return v <= 0;
    };

    const isLowStock = (el) => {
        const stock = parseFloat(el.dataset.stockMeters || el.getAttribute('data-stock-meters') || '0');
        const btn = el.querySelector('[data-recommended-stock]');
        const recommended = parseFloat(
            (btn && (btn.dataset.recommendedValue || btn.getAttribute('data-recommended-value')))
            || el.dataset.recommendedMeters
            || el.getAttribute('data-recommended-meters')
            || '0',
        );

        return recommended > 0 && stock < recommended;
    };

    const isCollectionCollapsed = (state, key) => {
        if ((state.search || '').trim()) {
            return false;
        }

        return !! state.collapsed[key];
    };

    const syncOrderedClasses = (wrap) => {
        if (! wrap) {
            return;
        }

        wrap.querySelectorAll('.fi-cargo-order-row').forEach((row) => {
            row.classList.toggle('fi-cargo-order-row--ordered', rowAmount(row) > 0);
            row.classList.toggle('fi-cargo-order-row--low-stock', isLowStock(row));
        });
    };

    const applyFilters = (chrome) => {
        const state = stateFor(chrome);
        const wrap = wrapFor(chrome);
        if (! wrap) {
            return;
        }

        let visibleCount = 0;

        wrap.querySelectorAll('.fi-cargo-order-row').forEach((row) => {
            const visible = rowVisible(state, row);
            row.classList.toggle('is-filtered-out', ! visible);
            row.classList.toggle('fi-cargo-order-row--low-stock', isLowStock(row));
            row.classList.toggle('fi-cargo-order-row--ordered', rowAmount(row) > 0);
            if (visible) {
                visibleCount++;
            }
        });

        wrap.querySelectorAll('[data-collection-key]').forEach((collection) => {
            const rows = collection.querySelectorAll('.fi-cargo-order-row');
            let anyVisible = rows.length === 0;
            rows.forEach((row) => {
                if (! row.classList.contains('is-filtered-out')) {
                    anyVisible = true;
                }
            });
            collection.classList.toggle('is-filtered-out', ! anyVisible);
        });

        const empty = chrome.querySelector('[data-cargo-order-empty]');
        if (empty) {
            const hasRows = wrap.querySelector('.fi-cargo-order-row') !== null;
            empty.hidden = ! hasRows || visibleCount > 0;
        }
    };

    const applyViewClass = (chrome) => {
        const state = stateFor(chrome);
        const wrap = wrapFor(chrome);
        if (! wrap) {
            return;
        }
        wrap.classList.toggle('fi-cargo-order-wrap--grid', state.view === 'grid');

        const head = chrome.querySelector('[data-cargo-order-head]');
        if (head) {
            head.hidden = state.view !== 'list';
        }
    };

    const syncCollapsedClasses = (chrome) => {
        const state = stateFor(chrome);
        const wrap = wrapFor(chrome);
        if (! wrap) {
            return;
        }

        wrap.querySelectorAll('[data-collection-key]').forEach((el) => {
            const collapsed = isCollectionCollapsed(state, el.dataset.collectionKey);
            el.classList.toggle('is-collapsed', collapsed);
            el.setAttribute('data-collapsed', collapsed ? 'true' : 'false');
            el.querySelectorAll('.fi-cargo-order-collection-title').forEach((btn) => {
                btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            });
            el.querySelectorAll('.fi-cargo-order-collection-chevron').forEach((chevron) => {
                chevron.classList.toggle('is-collapsed', collapsed);
            });
        });
    };

    const syncToolbar = (chrome) => {
        const state = stateFor(chrome);
        const search = chrome.querySelector('[data-cargo-order-search]');
        if (search && search.value !== state.search) {
            search.value = state.search;
        }

        chrome.querySelectorAll('[data-cargo-order-filter]').forEach((btn) => {
            btn.classList.toggle('is-active', btn.dataset.cargoOrderFilter === state.filter);
        });

        chrome.querySelectorAll('[data-cargo-order-view]').forEach((btn) => {
            btn.classList.toggle('is-active', btn.dataset.cargoOrderView === state.view);
        });
    };

    const closeRecommendedEditor = (chrome) => {
        const state = stateFor(chrome);
        state.recEditor.open = false;
        const pop = chrome.querySelector('[data-cargo-order-rec-popover]');
        if (pop) {
            pop.hidden = true;
        }
    };

    const openRecommendedEditor = (chrome, colorId, event) => {
        event.preventDefault();
        event.stopPropagation();
        const btn = event.currentTarget;
        const amount = parseInt(btn?.dataset?.recommendedValue || '0', 10) || 0;
        const rect = btn.getBoundingClientRect();
        const width = 220;
        const top = rect.bottom + 8;
        const left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
        const state = stateFor(chrome);

        state.recEditor = {
            open: true,
            colorId: Number(colorId) || 0,
            value: amount,
            top,
            left,
        };

        const pop = chrome.querySelector('[data-cargo-order-rec-popover]');
        const input = chrome.querySelector('[data-cargo-order-rec-input]');
        if (pop) {
            pop.hidden = false;
            pop.style.top = `${top}px`;
            pop.style.left = `${left}px`;
        }
        if (input) {
            input.value = String(amount);
            queueMicrotask(() => {
                input.focus();
                input.select();
            });
        }
    };

    const findLivewireComponent = (chrome) => {
        const root = chrome.closest('[wire\\:id]');
        const id = root?.getAttribute('wire:id');
        if (! id || ! window.Livewire?.find) {
            return null;
        }

        try {
            return window.Livewire.find(id);
        } catch (error) {
            return null;
        }
    };

    const saveRecommendedEditor = async (chrome) => {
        const state = stateFor(chrome);
        const input = chrome.querySelector('[data-cargo-order-rec-input]');
        const colorId = Number(state.recEditor.colorId) || 0;
        const amount = Math.max(0, parseInt(input?.value ?? state.recEditor.value, 10) || 0);
        const wrap = wrapFor(chrome) || document;
        const wire = findLivewireComponent(chrome);

        if (colorId > 0 && wire && typeof wire.updateColorRecommendedStock === 'function') {
            await wire.updateColorRecommendedStock(colorId, amount);
        }

        wrap.querySelectorAll(`[data-recommended-stock][data-color-id="${colorId}"]`).forEach((el) => {
            el.dataset.recommendedValue = String(amount);
            const label = el.querySelector('[data-rec-label]');
            if (label) {
                label.textContent = String(amount);
            }
        });

        wrap.querySelectorAll(`.fi-cargo-order-row[data-color-id="${colorId}"]`).forEach((row) => {
            row.dataset.recommendedMeters = String(amount);
        });

        applyFilters(chrome);
        closeRecommendedEditor(chrome);
    };

    const setFilter = (chrome, filter) => {
        const state = stateFor(chrome);
        state.filter = filter;
        try {
            localStorage.setItem(STORAGE.filter, filter);
        } catch (error) {}
        syncToolbar(chrome);
        applyFilters(chrome);
    };

    const setView = (chrome, view) => {
        const state = stateFor(chrome);
        state.view = view;
        try {
            localStorage.setItem(STORAGE.view, view);
        } catch (error) {}
        syncToolbar(chrome);
        applyViewClass(chrome);
        syncCollapsedClasses(chrome);
    };

    const setSearch = (chrome, value) => {
        const state = stateFor(chrome);
        state.search = value ?? '';
        try {
            localStorage.setItem(STORAGE.search, state.search);
        } catch (error) {}
        applyFilters(chrome);
        syncCollapsedClasses(chrome);
    };

    const toggleCollection = (chrome, key) => {
        const state = stateFor(chrome);
        state.collapsed = { ...state.collapsed, [key]: ! state.collapsed[key] };
        persistCollapsed(state);
        syncCollapsedClasses(chrome);
    };

    const expandAll = (chrome) => {
        const state = stateFor(chrome);
        state.collapsed = {};
        persistCollapsed(state);
        syncCollapsedClasses(chrome);
    };

    const collapseAll = (chrome) => {
        const state = stateFor(chrome);
        const wrap = wrapFor(chrome);
        const next = {};
        if (wrap) {
            wrap.querySelectorAll('[data-collection-key]').forEach((el) => {
                next[el.dataset.collectionKey] = true;
            });
        }
        state.collapsed = next;
        persistCollapsed(state);
        syncCollapsedClasses(chrome);
    };

    const ensureCatalogVisible = (chrome) => {
        const wrapAfter = wrapFor(chrome);
        if (wrapAfter && stateFor(chrome).filter !== 'all') {
            const visibleRows = wrapAfter.querySelectorAll('.fi-cargo-order-row:not(.is-filtered-out)');
            if (visibleRows.length === 0 && wrapAfter.querySelector('.fi-cargo-order-row')) {
                setFilter(chrome, 'all');
            }
        }
    };

    const bindChrome = (chrome) => {
        if (chrome.dataset.fiCargoOrderBound === '1') {
            readStorage(chrome);
            defaultCollapseAllIfUnset(chrome);
            syncToolbar(chrome);
            applyViewClass(chrome);
            syncCollapsedClasses(chrome);
            applyFilters(chrome);
            ensureCatalogVisible(chrome);
            syncOrderedClasses(wrapFor(chrome));

            return;
        }

        chrome.dataset.fiCargoOrderBound = '1';
        readStorage(chrome);
        defaultCollapseAllIfUnset(chrome);
        syncToolbar(chrome);
        applyViewClass(chrome);
        syncCollapsedClasses(chrome);
        applyFilters(chrome);
        ensureCatalogVisible(chrome);
        syncOrderedClasses(wrapFor(chrome));

        const search = chrome.querySelector('[data-cargo-order-search]');
        search?.addEventListener('input', () => setSearch(chrome, search.value));

        chrome.querySelectorAll('[data-cargo-order-filter]').forEach((btn) => {
            btn.addEventListener('click', () => setFilter(chrome, btn.dataset.cargoOrderFilter));
        });

        chrome.querySelectorAll('[data-cargo-order-view]').forEach((btn) => {
            btn.addEventListener('click', () => setView(chrome, btn.dataset.cargoOrderView));
        });

        chrome.querySelector('[data-cargo-order-expand]')?.addEventListener('click', () => expandAll(chrome));
        chrome.querySelector('[data-cargo-order-collapse]')?.addEventListener('click', () => collapseAll(chrome));

        chrome.querySelector('[data-cargo-order-rec-save]')?.addEventListener('click', () => {
            void saveRecommendedEditor(chrome);
        });
        chrome.querySelector('[data-cargo-order-rec-cancel]')?.addEventListener('click', () => closeRecommendedEditor(chrome));
        chrome.querySelector('[data-cargo-order-rec-input]')?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                void saveRecommendedEditor(chrome);
            }
        });

        const wrap = wrapFor(chrome);
        if (wrap && wrap.dataset.fiCargoOrderWrapBound !== '1') {
            wrap.dataset.fiCargoOrderWrapBound = '1';

            wrap.addEventListener('input', (event) => {
                if (event.target.matches('[data-order-amount-input]')) {
                    const max = parseInt(event.target.getAttribute('max') || '0', 10);
                    let v = parseInt(event.target.value || '0', 10);
                    if (! Number.isFinite(v) || v < 0) {
                        v = 0;
                    }
                    if (Number.isFinite(max) && max > 0 && v > max) {
                        v = max;
                        event.target.value = String(max);
                    }

                    const productId = parseInt(event.target.getAttribute('data-order-amount-input') || '0', 10);
                    const wire = findLivewireComponent(chrome);
                    if (productId > 0 && wire && typeof wire.onOrderAmountInputUpdated === 'function') {
                        clearTimeout(event.target.__fiOrderAmountSyncTimer);
                        event.target.__fiOrderAmountSyncTimer = setTimeout(() => {
                            wire.onOrderAmountInputUpdated(productId, v);
                        }, 300);
                    }
                }

                if (event.target.matches('[data-cargo-product-amount], [data-order-amount-input]')) {
                    const row = event.target.closest('.fi-cargo-order-row');
                    if (row) {
                        row.classList.toggle('fi-cargo-order-row--ordered', rowAmount(row) > 0);
                    }
                    applyFilters(chrome);
                    if (event.target.matches('[data-order-amount-input]')) {
                        window.dispatchEvent(new CustomEvent('order-amounts-changed'));
                    }
                }
            });

            wrap.addEventListener('change', (event) => {
                if (event.target.matches('[data-order-amount-input]')) {
                    applyFilters(chrome);

                    const productId = parseInt(event.target.getAttribute('data-order-amount-input') || '0', 10);
                    let amount = parseInt(event.target.value || '0', 10);
                    if (! Number.isFinite(amount) || amount < 0) {
                        amount = 0;
                    }
                    const max = parseInt(event.target.getAttribute('max') || '0', 10);
                    if (Number.isFinite(max) && max > 0 && amount > max) {
                        amount = max;
                        event.target.value = String(max);
                    }

                    clearTimeout(event.target.__fiOrderAmountSyncTimer);
                    const wire = findLivewireComponent(chrome);
                    if (productId > 0 && wire && typeof wire.onOrderAmountInputUpdated === 'function') {
                        wire.onOrderAmountInputUpdated(productId, amount);
                    }

                    return;
                }

                if (event.target.matches('[data-cargo-product-amount]')) {
                    applyFilters(chrome);
                }
            });

            wrap.addEventListener('click', (event) => {
                const title = event.target.closest('.fi-cargo-order-collection-title');
                if (title) {
                    const root = title.closest('[data-collection-key]');
                    if (root?.dataset.collectionKey) {
                        toggleCollection(chrome, root.dataset.collectionKey);
                    }

                    return;
                }

                const recBtn = event.target.closest('[data-recommended-stock]');
                if (recBtn && ! recBtn.classList.contains('is-disabled')) {
                    openRecommendedEditor(chrome, recBtn.dataset.colorId, {
                        preventDefault: () => event.preventDefault(),
                        stopPropagation: () => event.stopPropagation(),
                        currentTarget: recBtn,
                    });
                }
            });
        }

        window.__cargoOrderPicker = {
            openRecommendedEditor: (colorId, event) => openRecommendedEditor(chrome, colorId, event),
            closeRecommendedEditor: () => closeRecommendedEditor(chrome),
            applyFilters: () => applyFilters(chrome),
        };
    };

    const boot = () => {
        document.querySelectorAll('.fi-cargo-order-chrome').forEach(bindChrome);
    };

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        document.querySelectorAll('.fi-cargo-order-chrome').forEach((chrome) => {
            if (stateFor(chrome).recEditor.open) {
                closeRecommendedEditor(chrome);
            }
        });
    });

    document.addEventListener('click', (event) => {
        document.querySelectorAll('.fi-cargo-order-chrome').forEach((chrome) => {
            const state = stateFor(chrome);
            if (! state.recEditor.open) {
                return;
            }
            const pop = chrome.querySelector('[data-cargo-order-rec-popover]');
            if (pop && ! pop.contains(event.target) && ! event.target.closest('[data-recommended-stock]')) {
                closeRecommendedEditor(chrome);
            }
        });
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    document.addEventListener('livewire:navigated', boot);
    document.addEventListener('livewire:init', () => {
        if (! window.Livewire?.hook) {
            return;
        }
        window.Livewire.hook('morph.updated', () => {
            queueMicrotask(boot);
        });
        window.Livewire.hook('commit', ({ succeed }) => {
            succeed(() => queueMicrotask(boot));
        });
    });

    // Back-compat no-op so any leftover x-data="fiCargoOrderPickerFactory()" does not throw.
    window.fiCargoOrderPickerFactory = () => ({
        filter: 'all',
        search: '',
        bump: 0,
        view: 'list',
        collapsed: {},
        recEditor: { open: false, colorId: 0, value: 0, top: 0, left: 0 },
        init() {},
        setFilter() {},
        setView() {},
        expandAll() {},
        collapseAll() {},
        closeRecommendedEditor() {},
        saveRecommendedEditor() {},
        applyFilters() {},
        applyViewClass() {},
        syncCollapsedClasses() {},
        openRecommendedEditor() {},
    });
})();
