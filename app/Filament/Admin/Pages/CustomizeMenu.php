<?php

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Services\AdminNavigation;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;

class CustomizeMenu extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Customize menu';

    protected static ?string $slug = 'customize-menu';

    protected static ?string $navigationLabel = 'Customize menu';

    protected string $view = 'filament.admin.pages.customize-menu';

    /**
     * @var list<array{id: string, name: string, color: ?string, sort: int}>
     */
    public array $groups = [];

    /**
     * @var list<array{
     *     item_key: string,
     *     label: string,
     *     default_label: string,
     *     custom_label: ?string,
     *     visible: bool,
     *     sort: int,
     *     group_temp_id: ?string,
     *     is_landing: bool,
     *     default_group: ?string,
     *     icon: mixed
     * }>
     */
    public array $items = [];

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $state = AdminNavigation::editorStateFor($user);
        $this->groups = $state['groups'];
        $this->items = $state['items'];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')
                ->label('Reset to default')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Reset menu customization?')
                ->modalDescription('This removes your custom groups, order, labels, and landing page override.')
                ->action(function (): void {
                    /** @var User $user */
                    $user = auth()->user();
                    AdminNavigation::resetCustomization($user);
                    $state = AdminNavigation::editorStateFor($user);
                    $this->groups = $state['groups'];
                    $this->items = $state['items'];

                    Notification::make()
                        ->title('Menu reset to default')
                        ->success()
                        ->send();
                }),
            Action::make('save')
                ->label('Save menu')
                ->action(fn () => $this->save()),
        ];
    }

    public function save(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $groups = collect($this->groups)
            ->values()
            ->map(function (array $group, int $index): array {
                return [
                    'id' => $group['id'] ?? ('tmp-'.$index),
                    'name' => trim((string) ($group['name'] ?? '')),
                    'color' => $group['color'] ?? null,
                    'sort' => (int) ($group['sort'] ?? $index),
                ];
            })
            ->filter(fn (array $group): bool => $group['name'] !== '')
            ->values()
            ->all();

        $items = collect($this->items)
            ->values()
            ->map(function (array $item, int $index): array {
                $custom = isset($item['custom_label']) ? trim((string) $item['custom_label']) : null;

                return [
                    'item_key' => (string) ($item['item_key'] ?? ''),
                    'visible' => (bool) ($item['visible'] ?? true),
                    'sort' => (int) ($item['sort'] ?? $index),
                    'custom_label' => $custom !== '' ? $custom : null,
                    'group_temp_id' => $item['group_temp_id'] ?? null,
                    'is_landing' => (bool) ($item['is_landing'] ?? false),
                ];
            })
            ->filter(fn (array $item): bool => $item['item_key'] !== '')
            ->values()
            ->all();

        AdminNavigation::saveCustomization($user, $groups, $items);

        $state = AdminNavigation::editorStateFor($user);
        $this->groups = $state['groups'];
        $this->items = $state['items'];

        Notification::make()
            ->title('Menu saved')
            ->success()
            ->send();
    }

    public function addGroup(): void
    {
        $this->groups[] = [
            'id' => 'new-'.Str::uuid()->toString(),
            'name' => 'New group',
            'color' => '#EB8027',
            'sort' => count($this->groups),
        ];
    }

    public function deleteGroup(string $groupId): void
    {
        $this->groups = collect($this->groups)
            ->reject(fn (array $group): bool => (string) ($group['id'] ?? '') === $groupId)
            ->values()
            ->all();

        $this->items = collect($this->items)
            ->map(function (array $item) use ($groupId): array {
                if ((string) ($item['group_temp_id'] ?? '') === $groupId) {
                    $item['group_temp_id'] = null;
                }

                return $item;
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array{id: string, items: list<string>}>  $payload
     */
    public function reorderFromClient(array $payload): void
    {
        $groupOrder = [];
        $itemSort = 0;
        $updatedItems = collect($this->items)->keyBy('item_key');

        foreach ($payload as $groupIndex => $bucket) {
            $groupId = (string) ($bucket['id'] ?? '');

            if ($groupId !== '' && $groupId !== '__ungrouped') {
                $groupOrder[] = $groupId;
            }

            foreach (($bucket['items'] ?? []) as $itemKey) {
                $key = (string) $itemKey;

                if (! $updatedItems->has($key)) {
                    continue;
                }

                $item = $updatedItems->get($key);
                $item['group_temp_id'] = ($groupId === '' || $groupId === '__ungrouped') ? null : $groupId;
                $item['sort'] = $itemSort++;
                $updatedItems->put($key, $item);
            }
        }

        $this->groups = collect($this->groups)
            ->sortBy(function (array $group) use ($groupOrder): int {
                $id = (string) ($group['id'] ?? '');
                $index = array_search($id, $groupOrder, true);

                return $index === false ? 9999 : $index;
            })
            ->values()
            ->map(function (array $group, int $index): array {
                $group['sort'] = $index;

                return $group;
            })
            ->all();

        $this->items = $updatedItems
            ->sortBy('sort')
            ->values()
            ->all();
    }

    public function setLanding(string $itemKey): void
    {
        $this->items = collect($this->items)
            ->map(function (array $item) use ($itemKey): array {
                $item['is_landing'] = (string) ($item['item_key'] ?? '') === $itemKey;

                return $item;
            })
            ->values()
            ->all();
    }

    public function toggleVisible(string $itemKey): void
    {
        $this->items = collect($this->items)
            ->map(function (array $item) use ($itemKey): array {
                if ((string) ($item['item_key'] ?? '') === $itemKey) {
                    $item['visible'] = ! (bool) ($item['visible'] ?? true);

                    if (! $item['visible']) {
                        $item['is_landing'] = false;
                    }
                }

                return $item;
            })
            ->values()
            ->all();
    }
}
