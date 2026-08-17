<?php

namespace App\Filament\Admin\Resources\MailTemplates\Pages;

use App\Filament\Admin\Resources\MailTemplates\MailTemplateResource;
use App\Filament\Admin\Resources\Pages\EditRecord;
use Filament\Actions\Action;

class EditMailTemplate extends EditRecord
{
    protected static string $resource = MailTemplateResource::class;

    /**
     * @return array<Action | \Filament\Actions\ActionGroup>
     */
    protected function buildHeaderActions(): array
    {
        return [
            MailTemplateResource::previewAction(),
            MailTemplateResource::previewInBrowserAction(),
            ...parent::buildHeaderActions(),
        ];
    }
}
