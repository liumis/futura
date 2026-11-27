<?php

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Services\AdminNavigation;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;

class ViewAllMenu extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'All menu items';

    protected static ?string $slug = 'view-all-menu';

    protected string $view = 'filament.admin.pages.view-all-menu';

    /**
     * @var list<array{key: string, label: string, group: ?string, url: ?string, icon: mixed}>
     */
    public array $overflowItems = [];

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::SevenExtraLarge;
    }

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $this->overflowItems = AdminNavigation::overflowItemsFor($user);
    }
}
