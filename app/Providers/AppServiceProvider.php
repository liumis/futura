<?php

namespace App\Providers;

use App\Filament\Auth\Http\Responses\LoginResponse;
use App\Listeners\BrandOutgoingEmail;
use App\Listeners\LogSentEmail;
use App\Listeners\PreventEmailSendingInTestMode;
use App\Models\Cargo;
use App\Models\Collection;
use App\Models\CustomerLevel;
use App\Models\CustomerLevelPrice;
use App\Models\Dividend;
use App\Models\DividendPaymentReport;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeMonthlyPayment;
use App\Models\EmployeeOneTimePayment;
use App\Models\EmployeePaymentReport;
use App\Models\Invoice;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestType;
use App\Models\LtHoliday;
use App\Models\ManualImport;
use App\Models\Order;
use App\Models\OvertimeRequest;
use App\Models\OvertimeRequestType;
use App\Models\Product;
use App\Models\ShippingSetting;
use App\Models\StockManualUpdate;
use App\Models\Todo;
use App\Models\TodoComment;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseImport;
use App\Models\WorkSchedule;
use App\Models\WriteOffDocument;
use App\Notifications\FilamentResetPassword;
use App\Observers\CargoObserver;
use App\Observers\CollectionObserver;
use App\Observers\CustomerLevelObserver;
use App\Observers\CustomerLevelPriceObserver;
use App\Observers\InvoiceObserver;
use App\Observers\ManualImportObserver;
use App\Observers\ModelActivityObserver;
use App\Observers\OrderObserver;
use App\Observers\ProductObserver;
use App\Observers\ShippingSettingObserver;
use App\Observers\TodoCommentObserver;
use App\Observers\TodoObserver;
use App\Observers\UserObserver;
use App\Services\ActivityLogger;
use App\Support\DateFormats;
use App\Support\UploadLimits;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Auth\Notifications\ResetPassword as FilamentResetPasswordNotification;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Pages\BasePage;
use Filament\Schemas\Schema;
use Filament\Tables\Table as FilamentTable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LoginResponseContract::class, LoginResponse::class);
        $this->app->bind(FilamentResetPasswordNotification::class, FilamentResetPassword::class);
        $this->app->singleton(\App\Contracts\CalendarProviderInterface::class, \App\Services\Calendar\MicrosoftCalendarProvider::class);
        $this->app->singleton(\App\Services\Calendar\MicrosoftCalendarProvider::class);
        $this->app->singleton(\App\Services\Calendar\CalendarSyncService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Keep Save / Cancel visible while scrolling long edit forms.
        BasePage::stickyFormActions();

        // Raise Livewire temp upload limit to 40 MB and set Filament FileUpload default maxSize.
        config([
            'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.UploadLimits::MAX_KILOBYTES],
        ]);
        UploadLimits::configureDefaults();

        FilamentTable::configureUsing(function (FilamentTable $table): void {
            $table
                ->paginationPageOptions([20, 50, 100])
                ->defaultPaginationPageOption(20)
                ->defaultDateDisplayFormat(DateFormats::DATE)
                ->defaultDateTimeDisplayFormat(DateFormats::DATETIME)
                ->defaultTimeDisplayFormat(DateFormats::TIME);
        });

        DatePicker::configureUsing(function (DatePicker $component): void {
            $component
                ->native(false)
                ->displayFormat(DateFormats::DATE)
                ->format(DateFormats::DATE)
                ->placeholder('YYYY-mm-dd')
                ->closeOnDateSelection()
                ->view('filament.forms.components.typed-date-picker');
        });

        DateTimePicker::configureUsing(function (DateTimePicker $component): void {
            $component
                ->defaultDateDisplayFormat(DateFormats::DATE)
                ->defaultDateTimeDisplayFormat(DateFormats::DATETIME)
                ->defaultDateTimeWithSecondsDisplayFormat(DateFormats::DATETIME)
                ->defaultTimeDisplayFormat(DateFormats::TIME)
                ->defaultTimeWithSecondsDisplayFormat(DateFormats::TIME);
        });

        Schema::configureUsing(function (Schema $schema): void {
            $schema
                ->defaultDateDisplayFormat(DateFormats::DATE)
                ->defaultDateTimeDisplayFormat(DateFormats::DATETIME)
                ->defaultTimeDisplayFormat(DateFormats::TIME);
        });

        Order::observe(OrderObserver::class);
        Cargo::observe(CargoObserver::class);
        Product::observe(ProductObserver::class);
        Collection::observe(CollectionObserver::class);
        CustomerLevel::observe(CustomerLevelObserver::class);
        CustomerLevelPrice::observe(CustomerLevelPriceObserver::class);
        Invoice::observe(InvoiceObserver::class);
        Todo::observe(TodoObserver::class);
        TodoComment::observe(TodoCommentObserver::class);
        ShippingSetting::observe(ShippingSettingObserver::class);
        User::observe(UserObserver::class);
        ManualImport::observe(ManualImportObserver::class);

        foreach ([
            Employee::class,
            EmployeeContract::class,
            LeaveRequest::class,
            LeaveRequestType::class,
            OvertimeRequest::class,
            OvertimeRequestType::class,
            WorkSchedule::class,
            EmployeeMonthlyPayment::class,
            EmployeeOneTimePayment::class,
            EmployeePaymentReport::class,
            Dividend::class,
            DividendPaymentReport::class,
            Document::class,
            DocumentType::class,
            WriteOffDocument::class,
            Warehouse::class,
            WarehouseImport::class,
            StockManualUpdate::class,
            ManualImport::class,
            LtHoliday::class,
        ] as $auditable) {
            $auditable::observe(ModelActivityObserver::class);
        }

        Event::listen(Login::class, function (Login $event): void {
            ActivityLogger::logLogin($event->user);
        });

        Event::listen(Failed::class, function (Failed $event): void {
            ActivityLogger::logFailedLogin($event->user, $event->credentials);
        });

        Event::listen(MessageSending::class, BrandOutgoingEmail::class);
        Event::listen(MessageSending::class, PreventEmailSendingInTestMode::class);
        Event::listen(MessageSent::class, LogSentEmail::class);
    }
}
