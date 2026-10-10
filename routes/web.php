<?php

// Root path is handled by Filament panel (path: '').
Route::permanentRedirect('/callendar', '/calendar');

use App\Enums\InvoiceLanguage;
use App\Http\Controllers\DokobitPostbackController;
use App\Http\Controllers\MicrosoftCalendarOAuthController;
use App\Http\Controllers\MicrosoftCalendarWebhookController;
use App\Models\BankStatementImport;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\MailTemplate;
use App\Models\User;
use App\Models\WriteOffDocument;
use App\Services\ActivityLogger;
use App\Services\DocumentBinaryStore;
use App\Services\InvoicePdfGenerator;
use App\Services\SharepointGraphClient;
use App\Services\WriteOffDocumentPdfGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::post('/dokobit/postback', DokobitPostbackController::class)
    ->name('dokobit.postback');

Route::post('/webhooks/microsoft/calendar', MicrosoftCalendarWebhookController::class)
    ->name('webhooks.microsoft.calendar');

Route::middleware('auth')->group(function (): void {
    Route::get('/oauth/microsoft/calendar/redirect', [MicrosoftCalendarOAuthController::class, 'redirect'])
        ->name('oauth.microsoft.calendar.redirect');
    Route::get('/oauth/microsoft/calendar/callback', [MicrosoftCalendarOAuthController::class, 'callback'])
        ->name('oauth.microsoft.calendar.callback');

    Route::get('/annotate-preview', function (Request $request) {
        abort_unless($request->hasValidSignature(), 403);

        $path = (string) $request->query('path', '');
        if ($path === '' || str_contains($path, '..')) {
            abort(404);
        }

        if (! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        $absolute = Storage::disk('public')->path($path);
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
        ]);
    })->name('annotate.preview');
});

Route::get('/email-template-preview', function () {
    abort_unless(auth()->check() && (auth()->user()?->hasRole('admin') ?? false), 403);

    return response()->view('mail.branded-template', [
        'appName' => config('app.name', 'FuturaTextiles SS'),
        'logoUrl' => asset('images/logo.png'),
        'subject' => 'Branded email template preview',
        'heading' => 'Order update',
        'recipientName' => auth()->user()?->name ?? 'Customer',
        'intro' => 'Your order details have been updated. This template uses your current logo and brand color palette.',
        'detailsTitle' => 'Summary',
        'details' => "Order #: 100245\nStatus: Packed\nEstimated dispatch: ".now()->addDay()->format('Y-m-d'),
        'actionText' => 'View orders',
        'actionUrl' => url('/orders'),
        'footerNote' => 'Need help? Reply to this email and we will assist you.',
    ]);
})->middleware('auth')->name('mail.template.preview');

Route::get('/mail-templates/{mailTemplate}/preview', function (MailTemplate $mailTemplate) {
    $user = auth()->user();
    $userName = $user instanceof User
        ? (trim(implode(' ', array_filter([$user->name, $user->surname]))) ?: (string) $user->email)
        : 'Your name';

    $templateFromName = trim((string) ($mailTemplate->from_name ?? ''));

    return response()->view('mail.template-preview', [
        'appName' => config('app.name', 'FuturaTextiles SS'),
        'logoUrl' => asset('images/logo.png'),
        'templateName' => (string) $mailTemplate->name,
        'subject' => str_replace('{order_id}', '123', trim((string) $mailTemplate->subject)),
        'fromName' => $templateFromName !== '' ? $templateFromName.' | '.$userName : $userName,
        'body' => trim((string) $mailTemplate->text),
    ]);
})->middleware('signed')->name('mail-templates.preview');

Route::get('/documents/{document}/file', function (Document $document) {
    abort_unless(auth()->check() && (auth()->user()?->hasRole('admin') ?? false), 403);
    abort_unless(DocumentBinaryStore::hasFile($document), 404);

    try {
        $binary = DocumentBinaryStore::getBinary($document);
    } catch (Throwable) {
        abort(404);
    }

    $fileName = DocumentBinaryStore::downloadFileName($document);
    $mime = match (strtolower(pathinfo($fileName, PATHINFO_EXTENSION))) {
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        default => 'application/octet-stream',
    };

    ActivityLogger::logReportDownloaded(
        'Document file',
        pathinfo($fileName, PATHINFO_EXTENSION) ?: 'bin',
        $document,
        ['file_name' => $fileName, 'disposition' => 'inline'],
    );

    return response($binary, 200, [
        'Content-Type' => $mime,
        'Content-Disposition' => 'inline; filename="'.$fileName.'"',
        'Cache-Control' => 'private, no-store',
    ]);
})->middleware('auth')->name('documents.file');

Route::get('/invoices/{invoice}/file', function (Invoice $invoice, Request $request) {
    abort_unless(auth()->check() && (auth()->user()?->hasRole('admin') ?? false), 403);

    $lang = InvoiceLanguage::tryFrom((string) $request->query('lang', ''));

    if ($lang !== null && $invoice->canOpenLanguagePdf()) {
        $stored = $invoice->sharepointFileForLanguage($lang->value);
        $fileName = ($invoice->invoice_number ?? 'invoice-'.$invoice->id).'-'.strtoupper($lang->value).'.pdf';

        if (is_array($stored) && filled($stored['item_id'] ?? null)) {
            try {
                $binary = SharepointGraphClient::make()->downloadItemContent((string) $stored['item_id']);

                ActivityLogger::logReportDownloaded('Invoice PDF '.$invoice->displayNumber(), 'pdf', $invoice, [
                    'file_name' => $stored['name'] ?? $fileName,
                    'language' => $lang->value,
                    'invoice_id' => $invoice->getKey(),
                    'invoice_number' => $invoice->invoice_number,
                    'sharepoint_item_id' => $stored['item_id'],
                    'source' => 'sharepoint',
                ]);

                return response($binary, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="'.($stored['name'] ?? $fileName).'"',
                    'Cache-Control' => 'private, no-store',
                ]);
            } catch (Throwable) {
                if (filled($stored['web_url'] ?? null)) {
                    return redirect((string) $stored['web_url']);
                }
            }
        }

        // Fallback: generate on the fly if SharePoint copy is missing.
        $pdf = InvoicePdfGenerator::generateForInvoice($invoice, $lang);

        ActivityLogger::logReportDownloaded('Invoice PDF '.$invoice->displayNumber(), 'pdf', $invoice, [
            'file_name' => $fileName,
            'language' => $lang->value,
            'invoice_id' => $invoice->getKey(),
            'invoice_number' => $invoice->invoice_number,
            'source' => 'generated',
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$fileName.'"',
        ]);
    }

    if (filled($invoice->sharepoint_item_id)) {
        try {
            $binary = SharepointGraphClient::make()->downloadItemContent((string) $invoice->sharepoint_item_id);
        } catch (Throwable) {
            if (filled($invoice->sharepoint_web_url)) {
                ActivityLogger::logFileOpened(
                    'Invoice '.$invoice->displayNumber().' (SharePoint)',
                    pathinfo((string) ($invoice->file_name ?: $invoice->sharepoint_path ?: 'invoice'), PATHINFO_EXTENSION) ?: 'bin',
                    $invoice,
                    [
                        'file_name' => $invoice->file_name,
                        'invoice_id' => $invoice->getKey(),
                        'invoice_number' => $invoice->invoice_number,
                        'sharepoint_web_url' => $invoice->sharepoint_web_url,
                    ],
                );

                return redirect($invoice->sharepoint_web_url);
            }

            abort(404);
        }

        $fileName = $invoice->file_name ?: basename((string) ($invoice->sharepoint_path ?: 'invoice-file'));

        ActivityLogger::logReportDownloaded('Invoice '.$invoice->displayNumber().' (SharePoint)', (string) ($invoice->file_mime ?? 'bin'), $invoice, [
            'file_name' => $fileName,
            'invoice_id' => $invoice->getKey(),
            'invoice_number' => $invoice->invoice_number,
            'sharepoint_item_id' => $invoice->sharepoint_item_id,
        ]);

        return response($binary, 200, [
            'Content-Type' => (string) ($invoice->file_mime ?? 'application/octet-stream'),
            'Content-Disposition' => 'inline; filename="'.$fileName.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    abort(404);
})->middleware('auth')->name('invoices.file');

Route::get('/write-off-documents/{writeOffDocument}/file', function (WriteOffDocument $writeOffDocument, Request $request) {
    abort_unless(auth()->check() && (auth()->user()?->hasRole('admin') ?? false), 403);

    $language = InvoiceLanguage::tryFrom((string) $request->query('lang', '')) ?? InvoiceLanguage::English;
    $pdf = WriteOffDocumentPdfGenerator::generate($writeOffDocument, $language, logDownload: false);
    $fileName = ($writeOffDocument->document_number ?? 'write-off-'.$writeOffDocument->id).'-'.strtoupper($language->value).'.pdf';

    ActivityLogger::logReportDownloaded('Write-off document PDF', 'pdf', $writeOffDocument, [
        'file_name' => $fileName,
        'language' => $language->value,
    ]);

    return response($pdf, 200, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'inline; filename="'.$fileName.'"',
    ]);
})->middleware('auth')->name('write-off-documents.file');

Route::get('/bank-statement-imports/{import}/file', function (BankStatementImport $import) {
    abort_unless(auth()->check(), 403);
    abort_unless(filled($import->sharepoint_web_url), 404);

    $format = strtolower((string) pathinfo(
        (string) ($import->original_filename ?: $import->sharepoint_path ?: 'file'),
        PATHINFO_EXTENSION,
    )) ?: 'bin';

    ActivityLogger::logFileOpened('Bank statement', $format, $import, [
        'file_name' => $import->original_filename,
        'sharepoint_path' => $import->sharepoint_path,
        'sharepoint_web_url' => $import->sharepoint_web_url,
    ]);

    return redirect()->away((string) $import->sharepoint_web_url);
})->middleware('auth')->name('bank-statement-imports.file');
