<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class BankStatementImportFinishedNotification extends Notification
{
    public function __construct(
        public bool $success,
        public bool $duplicate,
        public string $body,
        public string $filename,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $title = $this->success
            ? 'Bank statement imported'
            : ($this->duplicate ? 'Duplicate bank statement' : 'Bank statement could not be imported');

        return [
            'type' => 'bank_statement_import',
            'title' => $title,
            'body' => $this->body,
            'icon' => $this->success
                ? 'heroicon-o-check-circle'
                : ($this->duplicate ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-x-circle'),
            'color' => $this->success ? 'success' : ($this->duplicate ? 'warning' : 'danger'),
            'url' => route('filament.admin.pages.bank-statements'),
            'link_label' => 'Open bank statements',
            'filename' => $this->filename,
        ];
    }
}
