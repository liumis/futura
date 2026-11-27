<?php

namespace App\Services;

use App\Enums\AdminModule;
use App\Filament\Admin\Pages\CustomizeMenu;
use App\Filament\Admin\Pages\MonthlyPayment;
use App\Filament\Admin\Pages\NewInvoice;
use App\Filament\Admin\Pages\ViewAllMenu;
use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Models\User;
use App\Models\UserNavigationGroup;
use App\Models\UserNavigationItem;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\Resources\Resource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AdminNavigation
{
    public const VIEW_ALL_KEY = 'meta:view-all';

    public const CTA_NEW_ORDER = 'cta:new-order';

    public const CTA_NEW_TASK = 'cta:new-task';

    public const CTA_NEW_INVOICE = 'cta:new-invoice';

    public const CTA_NEW_DOCUMENT = 'cta:new-document';

    public const CTA_PAYMENTS = 'cta:payments';

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     group: ?string,
     *     icon: mixed,
     *     sort: int,
     *     url: string,
     *     active_when: ?\Closure,
     *     extra_attributes: array<string, mixed>
     * }>
     */
    public static function ctaDefinitions(): array
    {
        return [
            [
                'key' => self::CTA_NEW_ORDER,
                'label' => 'New order',
                'group' => 'Orders',
                'icon' => 'heroicon-o-plus-circle',
                'sort' => 99,
                'url' => fn (): string => OrderResource::getUrl('create'),
                'active_when' => fn (): bool => request()->routeIs(OrderResource::getRouteBaseName().'.create'),
                'module' => AdminModule::Orders,
                'extra_attributes' => ['class' => 'fi-sidebar-new-order'],
            ],
            [
                'key' => self::CTA_NEW_TASK,
                'label' => 'TASK',
                'group' => 'Tasks',
                'icon' => 'heroicon-o-plus-circle',
                'sort' => 99,
                'url' => fn (): string => TaskResource::getUrl('create'),
                'active_when' => fn (): bool => request()->routeIs(TaskResource::getRouteBaseName().'.create'),
                'module' => AdminModule::Tasks,
                'extra_attributes' => ['class' => 'fi-sidebar-new-order'],
            ],
            [
                'key' => self::CTA_NEW_INVOICE,
                'label' => 'New invoice',
                'group' => 'Finances',
                'icon' => 'heroicon-o-plus-circle',
                'sort' => 99,
                'url' => fn (): string => NewInvoice::getUrl(),
                'active_when' => fn (): bool => request()->routeIs(NewInvoice::getRouteName()),
                'module' => AdminModule::Finances,
                'extra_attributes' => ['class' => 'fi-sidebar-new-order'],
            ],
            [
                'key' => self::CTA_NEW_DOCUMENT,
                'label' => 'New document',
                'group' => 'Documents',
                'icon' => 'heroicon-o-plus-circle',
                'sort' => 99,
                'url' => fn (): string => DocumentResource::getUrl('create'),
                'active_when' => fn (): bool => request()->routeIs(DocumentResource::getRouteBaseName().'.create'),
                'module' => AdminModule::Documents,
                'extra_attributes' => ['class' => 'fi-sidebar-new-order'],
            ],
            [
                'key' => self::CTA_PAYMENTS,
                'label' => 'Payments',
                'group' => 'Employees & contracts',
                'icon' => 'heroicon-o-plus-circle',
                'sort' => 99,
                'url' => fn (): string => MonthlyPayment::getUrl(),
                'active_when' => fn (): bool => request()->routeIs(MonthlyPayment::getRouteName())
                    || request()->routeIs(\App\Filament\Admin\Pages\PaymentReports::getRouteName()),
                'module' => AdminModule::EmployeesContracts,
                'extra_attributes' => ['class' => 'fi-sidebar-new-order'],
            ],
        ];
    }

    /**
     * @return list<NavigationItem>
     */
    public static function panelCtaNavigationItems(): array
    {
        $items = [];

        foreach (self::ctaDefinitions() as $cta) {
            $module = $cta['module'];

            $item = NavigationItem::make($cta['label'])
                ->label($cta['label'])
                ->icon($cta['icon'])
                ->url($cta['url'])
                ->group($cta['group'])
                ->sort($cta['sort'])
                ->visible(fn (): bool => auth()->user()?->canAccessModule($module) ?? false)
                ->isActiveWhen($cta['active_when'])
                ->extraAttributes($cta['extra_attributes']);

            $items[] = $item;
        }

        return $items;
    }

    public static function keyForClass(string $class): string
    {
        if (is_subclass_of($class, Resource::class)) {
            return 'resource:'.$class;
        }

        return 'page:'.$class;
    }

    public static function hasCustomization(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return UserNavigationItem::query()
            ->where('user_id', $user->getKey())
            ->exists();
    }

    /**
     * Catalog of ACL-allowed navigable entries for the current panel user.
     *
     * @return array<string, array{
     *     key: string,
     *     label: string,
     *     group: ?string,
     *     icon: mixed,
     *     sort: int,
     *     url: ?string,
     *     factory: \Closure(): NavigationItem,
     *     extra_attributes: array<string, mixed>
     * }>
     */
    public static function catalogFor(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $panel = self::adminPanel();
        $catalog = [];

        foreach ($panel->getPages() as $page) {
            if (in_array($page, [CustomizeMenu::class, ViewAllMenu::class], true)) {
                continue;
            }

            if (! $page::shouldRegisterNavigation()) {
                continue;
            }

            if (! $page::canAccess()) {
                continue;
            }

            $items = $page::getNavigationItems();
            $item = $items[0] ?? null;

            if (! $item instanceof NavigationItem || ! $item->isVisible()) {
                continue;
            }

            $key = self::keyForClass($page);
            $catalog[$key] = self::snapshotFromNavigationItem(
                $key,
                $item,
                fn (): NavigationItem => $page::getNavigationItems()[0],
            );
        }

        foreach ($panel->getResources() as $resource) {
            if (! $resource::shouldRegisterNavigation()) {
                continue;
            }

            if (! $resource::canAccess()) {
                continue;
            }

            $items = $resource::getNavigationItems();
            $item = $items[0] ?? null;

            if (! $item instanceof NavigationItem || ! $item->isVisible()) {
                continue;
            }

            $key = self::keyForClass($resource);
            $catalog[$key] = self::snapshotFromNavigationItem(
                $key,
                $item,
                fn (): NavigationItem => $resource::getNavigationItems()[0],
            );
        }

        foreach (self::ctaDefinitions() as $cta) {
            if (! $user->canAccessModule($cta['module'])) {
                continue;
            }

            $url = $cta['url'];
            $resolvedUrl = is_callable($url) ? $url() : $url;

            $catalog[$cta['key']] = [
                'key' => $cta['key'],
                'label' => $cta['label'],
                'group' => $cta['group'],
                'icon' => $cta['icon'],
                'sort' => $cta['sort'],
                'url' => $resolvedUrl,
                'factory' => function () use ($cta): NavigationItem {
                    return NavigationItem::make($cta['label'])
                        ->label($cta['label'])
                        ->icon($cta['icon'])
                        ->url($cta['url'])
                        ->sort($cta['sort'])
                        ->isActiveWhen($cta['active_when'])
                        ->extraAttributes($cta['extra_attributes']);
                },
                'extra_attributes' => $cta['extra_attributes'],
            ];
        }

        return $catalog;
    }

    public static function build(NavigationBuilder $builder): NavigationBuilder
    {
        /** @var User|null $user */
        $user = auth()->user();
        $catalog = self::catalogFor($user);

        if ($user === null || $catalog === []) {
            return $builder;
        }

        if (! self::hasCustomization($user)) {
            return self::buildDefault($builder, $catalog);
        }

        return self::buildCustom($builder, $user, $catalog);
    }

    /**
     * @param  array<string, array<string, mixed>>  $catalog
     */
    private static function buildDefault(NavigationBuilder $builder, array $catalog): NavigationBuilder
    {
        $grouped = collect($catalog)
            ->sortBy(fn (array $entry): array => [
                (string) ($entry['group'] ?? ''),
                (int) ($entry['sort'] ?? 0),
                (string) ($entry['label'] ?? ''),
            ])
            ->groupBy(fn (array $entry): string => (string) ($entry['group'] ?? ''));

        $registeredOrder = array_values(array_filter(array_map(
            fn (NavigationGroup|string $group): ?string => $group instanceof NavigationGroup
                ? $group->getLabel()
                : (is_string($group) ? $group : null),
            self::adminPanel()->getNavigationGroups(),
        )));

        $orderedGroupNames = collect($grouped->keys())
            ->sortBy(function (string $name) use ($registeredOrder): int {
                if ($name === '') {
                    return -1;
                }

                $index = array_search($name, $registeredOrder, true);

                return $index === false ? count($registeredOrder) + 1 : $index;
            })
            ->values();

        foreach ($orderedGroupNames as $groupName) {
            /** @var Collection<int, array<string, mixed>> $entries */
            $entries = $grouped->get($groupName, collect());
            $items = $entries
                ->map(fn (array $entry): NavigationItem => self::makeItem($entry))
                ->values()
                ->all();

            if ($groupName === '') {
                $builder->items($items);

                continue;
            }

            $builder->group(
                NavigationGroup::make($groupName)
                    ->collapsed()
                    ->items($items),
            );
        }

        return $builder;
    }

    /**
     * @param  array<string, array<string, mixed>>  $catalog
     */
    private static function buildCustom(NavigationBuilder $builder, User $user, array $catalog): NavigationBuilder
    {
        $groups = UserNavigationGroup::query()
            ->where('user_id', $user->getKey())
            ->orderBy('sort')
            ->get()
            ->keyBy('id');

        $prefs = UserNavigationItem::query()
            ->where('user_id', $user->getKey())
            ->orderBy('sort')
            ->get()
            ->keyBy('item_key');

        $primaryKeys = [];
        $overflow = [];

        /** @var array<string, list<NavigationItem>> $bucketed */
        $bucketed = [
            '__ungrouped' => [],
        ];

        foreach ($groups as $group) {
            $bucketed['g:'.$group->getKey()] = [];
        }

        $sort = 0;

        foreach ($prefs as $pref) {
            $entry = $catalog[$pref->item_key] ?? null;

            if ($entry === null) {
                continue;
            }

            if (! $pref->visible) {
                $overflow[$pref->item_key] = $entry;

                continue;
            }

            $primaryKeys[] = $pref->item_key;
            $item = self::makeItem($entry, $pref->custom_label, $sort++);

            if ($pref->group_id !== null && isset($bucketed['g:'.$pref->group_id])) {
                $bucketed['g:'.$pref->group_id][] = $item;
            } else {
                $bucketed['__ungrouped'][] = $item;
            }
        }

        // ACL-allowed items missing from prefs stay available via View all.
        foreach ($catalog as $key => $entry) {
            if (isset($prefs[$key]) || in_array($key, $primaryKeys, true)) {
                continue;
            }

            $overflow[$key] = $entry;
        }

        if ($bucketed['__ungrouped'] !== []) {
            $builder->items($bucketed['__ungrouped']);
        }

        foreach ($groups as $group) {
            $items = $bucketed['g:'.$group->getKey()] ?? [];

            if ($items === []) {
                continue;
            }

            $navGroup = NavigationGroup::make($group->name)
                ->collapsed()
                ->items($items);

            if (filled($group->color)) {
                $navGroup->extraSidebarAttributes(self::groupColorAttributes((string) $group->color));
            }

            $builder->group($navGroup);
        }

        if ($overflow !== []) {
            $builder->item(
                NavigationItem::make('View all')
                    ->label('View all')
                    ->icon('heroicon-o-ellipsis-horizontal-circle')
                    ->url(fn (): string => ViewAllMenu::getUrl())
                    ->sort(10_000)
                    ->isActiveWhen(fn (): bool => request()->routeIs(ViewAllMenu::getRouteName())),
            );
        }

        return $builder;
    }

    /**
     * @param  array{
     *     key?: string,
     *     label: string,
     *     group?: ?string,
     *     icon?: mixed,
     *     sort?: int,
     *     url?: ?string,
     *     factory?: \Closure(): NavigationItem,
     *     extra_attributes?: array<string, mixed>
     * }  $entry
     */
    public static function makeItem(array $entry, ?string $customLabel = null, ?int $sortOverride = null): NavigationItem
    {
        $label = filled($customLabel) ? $customLabel : (string) $entry['label'];

        if (($entry['factory'] ?? null) instanceof \Closure) {
            $item = ($entry['factory'])();
            $item->label($label);
            $item->sort($sortOverride ?? (int) ($entry['sort'] ?? 0));

            return $item;
        }

        $item = NavigationItem::make($label)
            ->label($label)
            ->icon($entry['icon'] ?? null)
            ->url($entry['url'] ?? null)
            ->sort($sortOverride ?? (int) ($entry['sort'] ?? 0));

        if (! empty($entry['extra_attributes'])) {
            $item->extraAttributes($entry['extra_attributes']);
        }

        return $item;
    }

    /**
     * @return array<string, string>
     */
    public static function groupColorAttributes(string $color): array
    {
        $color = trim($color);

        if ($color === '') {
            return [];
        }

        if (str_starts_with($color, '#')) {
            return [
                'data-nav-color' => $color,
                'style' => '--nav-group-color: '.$color,
                'class' => 'fi-sidebar-group--colored',
            ];
        }

        return [
            'data-nav-color' => $color,
            'class' => 'fi-sidebar-group--colored fi-sidebar-group--color-'.$color,
        ];
    }

    public static function urlForItemKey(User $user, string $itemKey): ?string
    {
        $catalog = self::catalogFor($user);
        $entry = $catalog[$itemKey] ?? null;

        if ($entry === null) {
            return null;
        }

        $url = $entry['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    public static function landingItemKey(User $user): ?string
    {
        $key = UserNavigationItem::query()
            ->where('user_id', $user->getKey())
            ->where('is_landing', true)
            ->value('item_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Persist customize-menu editor state.
     *
     * @param  list<array{id?: int|string|null, name: string, color?: ?string, sort: int}>  $groups
     * @param  list<array{
     *     item_key: string,
     *     visible: bool,
     *     sort: int,
     *     custom_label?: ?string,
     *     group_temp_id?: int|string|null,
     *     is_landing?: bool
     * }>  $items
     */
    public static function saveCustomization(User $user, array $groups, array $items): void
    {
        $catalog = self::catalogFor($user);
        $allowedKeys = array_keys($catalog);

        DB::transaction(function () use ($user, $groups, $items, $allowedKeys): void {
            UserNavigationItem::query()->where('user_id', $user->getKey())->delete();
            UserNavigationGroup::query()->where('user_id', $user->getKey())->delete();

            $tempToId = [];

            foreach (collect($groups)->sortBy('sort')->values() as $index => $group) {
                $name = trim((string) ($group['name'] ?? ''));

                if ($name === '') {
                    continue;
                }

                $created = UserNavigationGroup::query()->create([
                    'user_id' => $user->getKey(),
                    'name' => $name,
                    'color' => self::normalizeColor($group['color'] ?? null),
                    'sort' => (int) ($group['sort'] ?? $index),
                ]);

                $tempId = $group['id'] ?? $group['temp_id'] ?? null;

                if ($tempId !== null && $tempId !== '') {
                    $tempToId[(string) $tempId] = $created->getKey();
                }
            }

            $landingAssigned = false;

            foreach (collect($items)->sortBy('sort')->values() as $index => $item) {
                $key = (string) ($item['item_key'] ?? '');

                if ($key === '' || ! in_array($key, $allowedKeys, true)) {
                    continue;
                }

                $groupTemp = $item['group_temp_id'] ?? $item['group_id'] ?? null;
                $groupId = null;

                if ($groupTemp !== null && $groupTemp !== '' && $groupTemp !== '__ungrouped') {
                    $groupId = $tempToId[(string) $groupTemp] ?? null;
                }

                $isLanding = (! $landingAssigned) && (bool) ($item['is_landing'] ?? false);

                if ($isLanding) {
                    $landingAssigned = true;
                }

                $customLabel = isset($item['custom_label']) ? trim((string) $item['custom_label']) : null;

                UserNavigationItem::query()->create([
                    'user_id' => $user->getKey(),
                    'item_key' => $key,
                    'visible' => (bool) ($item['visible'] ?? true),
                    'sort' => (int) ($item['sort'] ?? $index),
                    'custom_label' => $customLabel !== '' ? $customLabel : null,
                    'group_id' => $groupId,
                    'is_landing' => $isLanding,
                ]);
            }
        });
    }

    public static function resetCustomization(User $user): void
    {
        DB::transaction(function () use ($user): void {
            UserNavigationItem::query()->where('user_id', $user->getKey())->delete();
            UserNavigationGroup::query()->where('user_id', $user->getKey())->delete();
        });
    }

    /**
     * Editor payload for Customize menu page.
     *
     * @return array{
     *     groups: list<array{id: string, name: string, color: ?string, sort: int}>,
     *     items: list<array{
     *         item_key: string,
     *         label: string,
     *         default_label: string,
     *         custom_label: ?string,
     *         visible: bool,
     *         sort: int,
     *         group_temp_id: ?string,
     *         is_landing: bool,
     *         default_group: ?string,
     *         icon: mixed
     *     }>
     * }
     */
    public static function editorStateFor(User $user): array
    {
        $catalog = self::catalogFor($user);

        $groups = UserNavigationGroup::query()
            ->where('user_id', $user->getKey())
            ->orderBy('sort')
            ->get();

        $prefs = UserNavigationItem::query()
            ->where('user_id', $user->getKey())
            ->orderBy('sort')
            ->get()
            ->keyBy('item_key');

        if ($groups->isEmpty() && $prefs->isEmpty()) {
            $defaultGroups = collect($catalog)
                ->pluck('group')
                ->filter()
                ->unique()
                ->values();

            $groupPayload = [];
            $groupNameToTemp = [];

            foreach ($defaultGroups as $index => $name) {
                $tempId = 'default-'.$index;
                $groupNameToTemp[$name] = $tempId;
                $groupPayload[] = [
                    'id' => $tempId,
                    'name' => (string) $name,
                    'color' => null,
                    'sort' => $index,
                ];
            }

            $itemPayload = [];
            $sort = 0;

            foreach (collect($catalog)->sortBy(fn (array $e): array => [
                (string) ($e['group'] ?? ''),
                (int) ($e['sort'] ?? 0),
                (string) $e['label'],
            ]) as $entry) {
                $defaultGroup = $entry['group'] ?? null;
                $itemPayload[] = [
                    'item_key' => $entry['key'],
                    'label' => $entry['label'],
                    'default_label' => $entry['label'],
                    'custom_label' => null,
                    'visible' => true,
                    'sort' => $sort++,
                    'group_temp_id' => $defaultGroup !== null
                        ? ($groupNameToTemp[$defaultGroup] ?? null)
                        : null,
                    'is_landing' => false,
                    'default_group' => $defaultGroup,
                    'icon' => $entry['icon'] ?? null,
                ];
            }

            return [
                'groups' => $groupPayload,
                'items' => $itemPayload,
            ];
        }

        $groupPayload = $groups->values()->map(function (UserNavigationGroup $group, int $index): array {
            return [
                'id' => (string) $group->getKey(),
                'name' => $group->name,
                'color' => $group->color,
                'sort' => $group->sort ?? $index,
            ];
        })->all();

        $itemPayload = [];
        $sort = 0;
        $seen = [];

        foreach ($prefs as $pref) {
            $entry = $catalog[$pref->item_key] ?? null;

            if ($entry === null) {
                continue;
            }

            $seen[$pref->item_key] = true;
            $itemPayload[] = [
                'item_key' => $pref->item_key,
                'label' => $entry['label'],
                'default_label' => $entry['label'],
                'custom_label' => $pref->custom_label,
                'visible' => (bool) $pref->visible,
                'sort' => $pref->sort ?? $sort,
                'group_temp_id' => $pref->group_id !== null ? (string) $pref->group_id : null,
                'is_landing' => (bool) $pref->is_landing,
                'default_group' => $entry['group'] ?? null,
                'icon' => $entry['icon'] ?? null,
            ];
            $sort++;
        }

        foreach ($catalog as $key => $entry) {
            if (isset($seen[$key])) {
                continue;
            }

            $itemPayload[] = [
                'item_key' => $key,
                'label' => $entry['label'],
                'default_label' => $entry['label'],
                'custom_label' => null,
                'visible' => false,
                'sort' => $sort++,
                'group_temp_id' => null,
                'is_landing' => false,
                'default_group' => $entry['group'] ?? null,
                'icon' => $entry['icon'] ?? null,
            ];
        }

        return [
            'groups' => $groupPayload,
            'items' => $itemPayload,
        ];
    }

    /**
     * @return list<array{key: string, label: string, group: ?string, url: ?string, icon: mixed}>
     */
    public static function overflowItemsFor(User $user): array
    {
        $catalog = self::catalogFor($user);

        if (! self::hasCustomization($user)) {
            return [];
        }

        $prefs = UserNavigationItem::query()
            ->where('user_id', $user->getKey())
            ->get()
            ->keyBy('item_key');

        $overflow = [];

        foreach ($catalog as $key => $entry) {
            $pref = $prefs->get($key);

            if ($pref === null || ! $pref->visible) {
                $overflow[] = [
                    'key' => $key,
                    'label' => filled($pref?->custom_label) ? (string) $pref->custom_label : (string) $entry['label'],
                    'group' => $entry['group'] ?? null,
                    'url' => $entry['url'] ?? null,
                    'icon' => $entry['icon'] ?? null,
                ];
            }
        }

        usort($overflow, fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $overflow;
    }

    private static function adminPanel(): Panel
    {
        return Filament::getPanel('admin');
    }

    /**
     * @param  \Closure(): NavigationItem  $factory
     * @return array{
     *     key: string,
     *     label: string,
     *     group: ?string,
     *     icon: mixed,
     *     sort: int,
     *     url: ?string,
     *     factory: \Closure(): NavigationItem,
     *     extra_attributes: array<string, mixed>
     * }
     */
    private static function snapshotFromNavigationItem(string $key, NavigationItem $item, \Closure $factory): array
    {
        $group = $item->getGroup();

        if ($group instanceof \UnitEnum) {
            $group = $group instanceof \BackedEnum ? (string) $group->value : $group->name;
        }

        $url = null;

        try {
            $url = $item->getUrl();
        } catch (Throwable) {
            $url = null;
        }

        return [
            'key' => $key,
            'label' => $item->getLabel(),
            'group' => is_string($group) ? $group : null,
            'icon' => $item->getIcon(),
            'sort' => (int) ($item->getSort() ?? 0),
            'url' => $url,
            'factory' => $factory,
            'extra_attributes' => $item->getExtraAttributes(),
        ];
    }

    private static function normalizeColor(mixed $color): ?string
    {
        if (! is_string($color)) {
            return null;
        }

        $color = trim($color);

        if ($color === '') {
            return null;
        }

        if (preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color) === 1) {
            return strtoupper($color);
        }

        $allowed = ['primary', 'danger', 'warning', 'success', 'info', 'gray'];

        $lower = strtolower($color);

        return in_array($lower, $allowed, true) ? $lower : null;
    }
}
