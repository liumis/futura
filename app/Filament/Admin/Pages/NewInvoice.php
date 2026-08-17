<?php

namespace App\Filament\Admin\Pages;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Support\UploadLimits;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

class NewInvoice extends Page implements HasForms
{
    use InteractsWithForms;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.admin.pages.new-invoice';

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->redirect(Invoices::getUrl(), navigate: true);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                FileUpload::make('pdf_path')
                    ->label('Upload file')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg'])
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->directory('invoices')
                    ->required()
                    ->helperText(UploadLimits::withExistingNote('Supported formats: PDF, JPG, JPEG.'))
                    ->columnSpanFull()
                    ->extraAttributes([
                        'class' => 'invoice-fancy-upload',
                    ])
                    ->downloadable()
                    ->openable(),

                Select::make('contact_id')
                    ->label('Company')
                    ->options(fn (): array => Contact::query()->orderBy('company_name')->pluck('company_name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->required(),

                DatePicker::make('invoice_date')
                    ->label('Invoice date')
                    ->required(),

                DatePicker::make('upload_date')
                    ->label('Upload date')
                    ->required(),

                Select::make('uploaded_by')
                    ->label('Uploaded user')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => auth()->id())
                    ->disabled()
                    ->dehydrated()
                    ->required(),
            ])
            ->columns(2);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save invoice')
                ->color('primary')
                ->action('create'),
        ];
    }

    public function create(): void
    {
        $state = $this->form->getState();

        Invoice::query()->create([
            'contact_id' => $state['contact_id'],
            'invoice_date' => $state['invoice_date'],
            'upload_date' => $state['upload_date'],
            'uploaded_by' => $state['uploaded_by'] ?? auth()->id(),
            'pdf_path' => $state['pdf_path'],
        ]);

        Notification::make()
            ->title('Invoice uploaded')
            ->success()
            ->send();

        $this->redirect(Invoices::getUrl(), navigate: true);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }
}
