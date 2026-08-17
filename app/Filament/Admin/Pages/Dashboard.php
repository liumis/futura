<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\Orders\OrderResource;
use Filament\Pages\Dashboard as BaseDashboard;
use UnitEnum;

class Dashboard extends BaseDashboard
{
    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Orders';

    protected static ?int $navigationSort = 2;

    public function mount(): void
    {
        $this->redirect(OrderResource::getUrl('index'), navigate: true);
    }
}
