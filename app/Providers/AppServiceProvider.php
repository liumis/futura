<?php

namespace App\Providers;

use App\Contracts\CalendarProviderInterface;
use App\Filament\Auth\Http\Responses\LoginResponse;
use App\Listeners\BrandOutgoingEmail;
use App\Listeners\LogSentEmail;
use App\Listeners\PreventEmailSendingInTestMode;
use App\Models\BankStatementImport;
use App\Models\Cargo;
use App\Models\Collection;
use App\Models\Color;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Currency;
use App\Models\CustomerLevel;
use App\Models\CustomerLevelPrice;
use App\Models\CustomsContact;
use App\Models\Destination;
use App\Models\DirectoryContact;
use App\Models\DirectoryContactStage;
use App\Models\Dividend;
use App\Models\DividendPaymentReport;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeMonthlyPayment;
use App\Models\EmployeeOneTimePayment;
use App\Models\EmployeePaymentReport;
use App\Models\ExpenseType;
use App\Models\ImportTax;
use App\Models\ImportTaxPayment;
use App\Models\IncomeType;
use App\Models\Invoice;
use App\Models\InvoiceCode;
use App\Models\InvoiceSeries;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestType;
use App\Models\LtHoliday;
use App\Models\ManualImport;
use App\Models\Note;
use App\Models\Order;
use App\Models\OriginCountry;
use App\Models\OvertimeRequest;
use App\Models\OvertimeRequestType;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductReservation;
use App\Models\ProductType;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Models\ProjectStage;
use App\Models\ProjectType;
use App\Models\Shareholder;
use App\Models\ShippingSetting;
use App\Models\StockManualUpdate;
use App\Models\Supplier;
use App\Models\Todo;
use App\Models\TodoComment;
use App\Models\User;
use App\Models\VatRate;
use App\Models\Warehouse;
use App\Models\WarehouseImport;
use App\Models\WorkSchedule;
use App\Models\WriteOffDocument;
use App\Notifications\FilamentResetPassword;
use App\Observers\CargoObserver;
use App\Observers\CollectionObserver;
use App\Observers\ContactObserver;
use App\Observers\CustomerLevelObserver;
use App\Observers\CustomerLevelPriceObserver;
use App\Observers\DocumentConfirmationObserver;
use App\Observers\EmployeeConfirmationObserver;
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
use App\Services\Calendar\CalendarSyncService;
use App\Services\Calendar\MicrosoftCalendarProvider;
use App\Support\DateFormats;
use App\Support\UploadLimits;
use Filament\Actions\Action;
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
        $this->app->singleton(CalendarProviderInterface::class, MicrosoftCalendarProvider::class);
        $this->app->singleton(MicrosoftCalendarProvider::class);
        $this->app->singleton(CalendarSyncService::class);
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

        // Keep modal Save / Cancel / Delete visible while scrolling long action forms
        // (e.g. Edit invoice).
        Action::configureUsing(function (Action $action): void {
            $action
                ->stickyModalHeader()
                ->stickyModalFooter();
        });

        // Raise Livewire temp upload limit to 40 MB and set Filament FileUpload default maxSize.
        config([
            'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.UploadLimits::MAX_KILOBYTES],
        ]);
        UploadLimits::configureDefaults();

        FilamentTable::configureUsing(function (FilamentTable $table): void {
            $table
                ->paginationPageOptions([20, 50, 100])
                ->defaultPaginationPageOption(50)
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
        Contact::observe(ContactObserver::class);
        Todo::observe(TodoObserver::class);
        TodoComment::observe(TodoCommentObserver::class);
        ShippingSetting::observe(ShippingSettingObserver::class);
        User::observe(UserObserver::class);
        ManualImport::observe(ManualImportObserver::class);
        Document::observe(DocumentConfirmationObserver::class);
        Employee::observe(EmployeeConfirmationObserver::class);

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
            ProductReservation::class,
            Payment::class,
            ManualImport::class,
            LtHoliday::class,
            BankStatementImport::class,
            Contact::class,
            Project::class,
            ProjectExpense::class,
            ProjectStage::class,
            ProjectType::class,
            Note::class,
            DirectoryContact::class,
            DirectoryContactStage::class,
            ImportTaxPayment::class,
            ImportTax::class,
            Supplier::class,
            CustomsContact::class,
            Shareholder::class,
            Color::class,
            Country::class,
            Destination::class,
            Package::class,
            ExpenseType::class,
            IncomeType::class,
            VatRate::class,
            Currency::class,
            OriginCountry::class,
            InvoiceSeries::class,
            InvoiceCode::class,
            ProductType::class,
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
