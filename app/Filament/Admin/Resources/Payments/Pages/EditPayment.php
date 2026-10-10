<?php

namespace App\Filament\Admin\Resources\Payments\Pages;

use App\Enums\PaymentSchedule;
use App\Filament\Admin\Resources\Pages\EditRecord;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use Filament\Actions;

class EditPayment extends EditRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $schedule = (string) ($data['schedule'] ?? PaymentSchedule::OneTime->value);

        if ($schedule === PaymentSchedule::Periodical->value) {
            $data['due_date'] = null;
            $data['paid'] = false;
        } else {
            $data['frequency'] = null;
            $data['start_date'] = null;
            $data['end_date'] = null;
        }

        return $data;
    }
}
