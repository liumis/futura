<?php



namespace App\Filament\Admin\Resources\WarehouseImports\Pages;



use App\Enums\WarehouseImportStatus;

use App\Filament\Admin\Resources\WarehouseImports\WarehouseImportResource;

use App\Filament\Admin\Resources\Pages\EditRecord;

use Filament\Actions\Action;

use Filament\Notifications\Notification;



class EditWarehouseImport extends EditRecord

{

    protected static string $resource = WarehouseImportResource::class;



    protected function beforeSave(): void

    {

        if ($this->record->status === WarehouseImportStatus::Received) {

            Notification::make()

                ->title('Cannot edit imported records')

                ->body('This received order has been marked as received and is read-only.')

                ->warning()

                ->send();



            $this->halt();

        }

    }



    protected function makeHeaderSaveAction(): Action

    {

        return parent::makeHeaderSaveAction()

            ->visible(fn (): bool => $this->record->status !== WarehouseImportStatus::Received);

    }

}

