<x-filament-panels::page>
    <div
        class="ss-nav-customize"
        x-data="ssNavCustomize(@js([
            'groups' => $groups,
            'items' => $items,
        ]))"
        x-on:livewire:navigated.window="reinit()"
    >
        <div class="ss-nav-customize__toolbar">
            <p class="ss-nav-customize__hint">
                Drag items between groups, rename labels, hide items (they appear under View all), and pick a home page.
                Only modules you can access are listed.
            </p>
            <button type="button" class="ss-nav-customize__btn" wire:click="addGroup">
                Add group
            </button>
        </div>

        <div class="ss-nav-customize__layout" x-ref="board">
            <section class="ss-nav-customize__group" data-group-id="__ungrouped">
                <header class="ss-nav-customize__group-head">
                    <div class="ss-nav-customize__group-title">Ungrouped</div>
                </header>
                <ul class="ss-nav-customize__list" data-group-id="__ungrouped">
                    <template x-for="item in itemsFor('__ungrouped')" :key="item.item_key">
                        <li class="ss-nav-customize__item" :data-item-key="item.item_key">
                            <span class="ss-nav-customize__handle" title="Drag">⋮⋮</span>
                            <div class="ss-nav-customize__item-main">
                                <input
                                    type="text"
                                    class="ss-nav-customize__label-input"
                                    :value="item.custom_label || item.default_label"
                                    x-on:change="renameItem(item.item_key, $event.target.value)"
                                    :placeholder="item.default_label"
                                >
                                <div class="ss-nav-customize__meta" x-text="item.default_group ? ('Default: ' + item.default_group) : 'No default group'"></div>
                            </div>
                            <div class="ss-nav-customize__item-actions">
                                <button
                                    type="button"
                                    class="ss-nav-customize__chip"
                                    :class="{ 'is-active': item.visible }"
                                    x-on:click="toggleVisible(item.item_key)"
                                    x-text="item.visible ? 'Visible' : 'Hidden'"
                                ></button>
                                <button
                                    type="button"
                                    class="ss-nav-customize__chip"
                                    :class="{ 'is-home': item.is_landing }"
                                    x-on:click="setLanding(item.item_key)"
                                    :disabled="!item.visible"
                                    x-text="item.is_landing ? 'Home' : 'Set home'"
                                ></button>
                            </div>
                        </li>
                    </template>
                </ul>
            </section>

            <template x-for="group in groups" :key="group.id">
                <section class="ss-nav-customize__group" :data-group-id="group.id">
                    <header class="ss-nav-customize__group-head">
                        <input
                            type="text"
                            class="ss-nav-customize__group-name"
                            :value="group.name"
                            x-on:change="renameGroup(group.id, $event.target.value)"
                        >
                        <input
                            type="color"
                            class="ss-nav-customize__color"
                            :value="normalizeHex(group.color)"
                            x-on:input="setGroupColor(group.id, $event.target.value)"
                            title="Group color"
                        >
                        <button
                            type="button"
                            class="ss-nav-customize__btn ss-nav-customize__btn--danger"
                            x-on:click="deleteGroup(group.id)"
                        >
                            Delete
                        </button>
                    </header>
                    <ul class="ss-nav-customize__list" :data-group-id="group.id">
                        <template x-for="item in itemsFor(group.id)" :key="item.item_key">
                            <li class="ss-nav-customize__item" :data-item-key="item.item_key" :class="{ 'is-hidden': !item.visible }">
                                <span class="ss-nav-customize__handle" title="Drag">⋮⋮</span>
                                <div class="ss-nav-customize__item-main">
                                    <input
                                        type="text"
                                        class="ss-nav-customize__label-input"
                                        :value="item.custom_label || item.default_label"
                                        x-on:change="renameItem(item.item_key, $event.target.value)"
                                        :placeholder="item.default_label"
                                    >
                                    <div class="ss-nav-customize__meta" x-text="item.default_group ? ('Default: ' + item.default_group) : ''"></div>
                                </div>
                                <div class="ss-nav-customize__item-actions">
                                    <button
                                        type="button"
                                        class="ss-nav-customize__chip"
                                        :class="{ 'is-active': item.visible }"
                                        x-on:click="toggleVisible(item.item_key)"
                                        x-text="item.visible ? 'Visible' : 'Hidden'"
                                    ></button>
                                    <button
                                        type="button"
                                        class="ss-nav-customize__chip"
                                        :class="{ 'is-home': item.is_landing }"
                                        x-on:click="setLanding(item.item_key)"
                                        :disabled="!item.visible"
                                        x-text="item.is_landing ? 'Home' : 'Set home'"
                                    ></button>
                                </div>
                            </li>
                        </template>
                    </ul>
                </section>
            </template>
        </div>
    </div>

    <script>
        if (!window.__ssNavCustomizeRegistered) {
            window.__ssNavCustomizeRegistered = true;
            document.addEventListener('alpine:init', () => {
                Alpine.data('ssNavCustomize', (initial) => ({
                    groups: initial.groups || [],
                    items: initial.items || [],
                    sortables: [],
                    init() {
                        this.$watch('$wire.groups', (value) => {
                            this.groups = Array.isArray(value) ? value : [];
                            this.$nextTick(() => this.initSortable());
                        });
                        this.$watch('$wire.items', (value) => {
                            this.items = Array.isArray(value) ? value : [];
                            this.$nextTick(() => this.initSortable());
                        });
                        this.$nextTick(() => this.initSortable());
                    },
                    reinit() {
                        this.groups = this.$wire.groups || [];
                        this.items = this.$wire.items || [];
                        this.$nextTick(() => this.initSortable());
                    },
                    itemsFor(groupId) {
                        const id = groupId === '__ungrouped' ? null : String(groupId);
                        return (this.items || [])
                            .filter((item) => {
                                const gid = item.group_temp_id == null || item.group_temp_id === ''
                                    ? null
                                    : String(item.group_temp_id);
                                return gid === id;
                            })
                            .sort((a, b) => (a.sort ?? 0) - (b.sort ?? 0));
                    },
                    normalizeHex(color) {
                        if (typeof color === 'string' && /^#[0-9A-Fa-f]{6}$/.test(color)) {
                            return color;
                        }
                        if (typeof color === 'string' && /^#[0-9A-Fa-f]{3}$/.test(color)) {
                            return color;
                        }
                        return '#6B6B6B';
                    },
                    renameGroup(id, name) {
                        const next = (this.$wire.groups || []).map((group) => {
                            if (String(group.id) !== String(id)) return group;
                            return { ...group, name };
                        });
                        this.$wire.set('groups', next);
                    },
                    setGroupColor(id, color) {
                        const next = (this.$wire.groups || []).map((group) => {
                            if (String(group.id) !== String(id)) return group;
                            return { ...group, color };
                        });
                        this.$wire.set('groups', next);
                    },
                    deleteGroup(id) {
                        this.$wire.deleteGroup(String(id));
                    },
                    renameItem(itemKey, value) {
                        const defaultLabel = (this.items || []).find((item) => item.item_key === itemKey)?.default_label;
                        const custom = (value || '').trim() === (defaultLabel || '').trim() ? null : (value || '').trim();
                        const next = (this.$wire.items || []).map((item) => {
                            if (item.item_key !== itemKey) return item;
                            return { ...item, custom_label: custom || null };
                        });
                        this.$wire.set('items', next);
                    },
                    toggleVisible(itemKey) {
                        this.$wire.toggleVisible(itemKey);
                    },
                    setLanding(itemKey) {
                        this.$wire.setLanding(itemKey);
                    },
                    destroySortable() {
                        (this.sortables || []).forEach((sortable) => sortable.destroy());
                        this.sortables = [];
                    },
                    initSortable() {
                        if (typeof Sortable === 'undefined') {
                            setTimeout(() => this.initSortable(), 100);
                            return;
                        }

                        this.destroySortable();

                        this.$el.querySelectorAll('.ss-nav-customize__list').forEach((list) => {
                            const sortable = Sortable.create(list, {
                                group: {
                                    name: 'ss-nav-customize',
                                    pull: true,
                                    put: true,
                                },
                                animation: 150,
                                draggable: '.ss-nav-customize__item',
                                handle: '.ss-nav-customize__handle',
                                ghostClass: 'ss-nav-customize__item--ghost',
                                onEnd: () => this.syncOrder(),
                            });
                            this.sortables.push(sortable);
                        });
                    },
                    syncOrder() {
                        const payload = [];
                        this.$el.querySelectorAll('.ss-nav-customize__group').forEach((section) => {
                            const id = section.getAttribute('data-group-id') || '__ungrouped';
                            const items = Array.from(section.querySelectorAll('.ss-nav-customize__item'))
                                .map((el) => el.getAttribute('data-item-key'))
                                .filter(Boolean);
                            payload.push({ id, items });
                        });
                        this.$wire.reorderFromClient(payload);
                    },
                }));
            });
        }
    </script>

    <style>
        .ss-nav-customize {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .ss-nav-customize__toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .ss-nav-customize__hint {
            margin: 0;
            max-width: 48rem;
            color: #6b6b6b;
            font-size: 0.875rem;
            line-height: 1.45;
        }

        .ss-nav-customize__layout {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr));
            gap: 0.85rem;
            align-items: start;
        }

        .ss-nav-customize__group {
            border: 1px solid #c9c9c9;
            background: #fff;
            min-height: 10rem;
        }

        .ss-nav-customize__group-head {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.55rem 0.65rem;
            border-bottom: 1px solid #e5e5e5;
            background: #f7f7f5;
        }

        .ss-nav-customize__group-title,
        .ss-nav-customize__group-name {
            flex: 1;
            min-width: 0;
            font-family: 'Jost', sans-serif;
            font-size: 0.72rem;
            font-weight: 500;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #222;
            border: 0;
            background: transparent;
        }

        .ss-nav-customize__color {
            width: 1.6rem;
            height: 1.6rem;
            padding: 0;
            border: 1px solid #c9c9c9;
            background: #fff;
            cursor: pointer;
        }

        .ss-nav-customize__list {
            list-style: none;
            margin: 0;
            padding: 0.4rem;
            min-height: 3rem;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }

        .ss-nav-customize__item {
            display: flex;
            align-items: flex-start;
            gap: 0.4rem;
            padding: 0.45rem 0.5rem;
            border: 1px solid #e5e5e5;
            background: #fff;
        }

        .ss-nav-customize__item.is-hidden {
            opacity: 0.55;
        }

        .ss-nav-customize__item--ghost {
            opacity: 0.45;
        }

        .ss-nav-customize__handle {
            cursor: grab;
            color: #9a9a9a;
            user-select: none;
            line-height: 1.2;
            padding-top: 0.15rem;
        }

        .ss-nav-customize__item-main {
            flex: 1;
            min-width: 0;
        }

        .ss-nav-customize__label-input {
            width: 100%;
            border: 0;
            border-bottom: 1px solid transparent;
            background: transparent;
            font-size: 0.875rem;
            color: #222;
            padding: 0.1rem 0;
        }

        .ss-nav-customize__label-input:focus {
            outline: none;
            border-bottom-color: #222;
        }

        .ss-nav-customize__meta {
            margin-top: 0.15rem;
            font-size: 0.7rem;
            color: #8a8a8a;
        }

        .ss-nav-customize__item-actions {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .ss-nav-customize__chip,
        .ss-nav-customize__btn {
            border: 1px solid #c9c9c9;
            background: #fff;
            color: #222;
            font-size: 0.7rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            padding: 0.25rem 0.45rem;
            cursor: pointer;
            white-space: nowrap;
        }

        .ss-nav-customize__chip.is-active {
            border-color: #222;
            background: #222;
            color: #fff;
        }

        .ss-nav-customize__chip.is-home {
            border-color: #eb8027;
            background: #eb8027;
            color: #fff;
        }

        .ss-nav-customize__chip:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        .ss-nav-customize__btn--danger {
            color: #8a2a2a;
            border-color: #d7b3b3;
        }
    </style>
</x-filament-panels::page>
