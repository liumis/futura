<?php

namespace App\Filament\Admin\Resources\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord as BaseEditRecord;

abstract class EditRecord extends BaseEditRecord
{
    /**
     * @return array<Action | \Filament\Actions\ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return $this->insertSaveBeforeDelete($this->buildHeaderActions());
    }

    /**
     * @return array<Action | \Filament\Actions\ActionGroup>
     */
    protected function buildHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<Action | \Filament\Actions\ActionGroup>  $actions
     * @return array<Action | \Filament\Actions\ActionGroup>
     */
    protected function insertSaveBeforeDelete(array $actions): array
    {
        $save = $this->makeHeaderSaveAction();
        $hasSave = false;
        $result = [];

        foreach ($actions as $action) {
            if ($action instanceof DeleteAction) {
                if (! $hasSave) {
                    $result[] = $save;
                    $hasSave = true;
                }
            }

            if ($action instanceof Action && $action->getName() === 'save') {
                $hasSave = true;
            }

            $result[] = $action;
        }

        if (! $hasSave) {
            $result[] = $save;
        }

        return $result;
    }

    protected function makeHeaderSaveAction(): Action
    {
        return Action::make('save')
            ->label(__('filament-panels::resources/pages/edit-record.form.actions.save.label'))
            ->action('save')
            ->keyBindings(['mod+s']);
    }
}
