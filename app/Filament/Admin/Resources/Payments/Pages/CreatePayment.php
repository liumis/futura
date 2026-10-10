<?php

namespace App\Filament\Admin\Resources\Payments\Pages;

use App\Enums\PaymentSchedule;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePayment extends CreateRecord
{
    protected static string $resource = PaymentResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['uploaded_by'] = $data['uploaded_by'] ?? auth()->id();

        return $this->normalizeScheduleFields($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeScheduleFields(array $data): array
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
