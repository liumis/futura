<?php

// Root path is handled by Filament panel (path: '').

use App\Http\Controllers\DokobitPostbackController;
use App\Http\Controllers\MicrosoftCalendarOAuthController;
use App\Http\Controllers\MicrosoftCalendarWebhookController;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\MailTemplate;
use App\Models\User;
use App\Models\WriteOffDocument;
use App\Enums\InvoiceLanguage;
use App\Services\DocumentBinaryStore;
use App\Services\ActivityLogger;
use App\Services\InvoicePdfGenerator;
use App\Services\WriteOffDocumentPdfGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

        if (! \Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
            abort(404);
        }

        $absolute = \Illuminate\Support\Facades\Storage::disk('public')->path($path);
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
        'logoUrl' => asset('images/logo.svg'),
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
        'logoUrl' => asset('images/logo.svg'),
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
    } catch (\Throwable) {
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

    if ($lang !== null && $invoice->order_id && $invoice->file_mime === 'application/pdf') {
        $pdf = InvoicePdfGenerator::generateForInvoice($invoice, $lang);
        $fileName = ($invoice->invoice_number ?? 'invoice-'.$invoice->id).'-'.strtoupper($lang->value).'.pdf';

        ActivityLogger::logReportDownloaded('Invoice PDF', 'pdf', $invoice, [
            'file_name' => $fileName,
            'language' => $lang->value,
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$fileName.'"',
        ]);
    }

    if (filled($invoice->file_content)) {
        $binary = base64_decode((string) $invoice->file_content, true);
        abort_if($binary === false, 404);

        ActivityLogger::logReportDownloaded('Invoice file', (string) ($invoice->file_mime ?? 'bin'), $invoice, [
            'file_name' => $invoice->file_name ?? 'invoice-file',
        ]);

        return response($binary, 200, [
            'Content-Type' => (string) ($invoice->file_mime ?? 'application/octet-stream'),
            'Content-Disposition' => 'inline; filename="'.($invoice->file_name ?? 'invoice-file').'"',
        ]);
    }

    // Backward compatibility for records saved before DB file storage.
    if (filled($invoice->pdf_path) && \Illuminate\Support\Facades\Storage::disk('public')->exists($invoice->pdf_path)) {
        ActivityLogger::logReportDownloaded('Invoice PDF (storage)', 'pdf', $invoice, [
            'pdf_path' => $invoice->pdf_path,
        ]);

        return redirect(\Illuminate\Support\Facades\Storage::url($invoice->pdf_path));
    }

    abort(404);
})->middleware('auth')->name('invoices.file');

Route::get('/write-off-documents/{writeOffDocument}/file', function (WriteOffDocument $writeOffDocument, Request $request) {
    abort_unless(auth()->check() && (auth()->user()?->hasRole('admin') ?? false), 403);

    $language = InvoiceLanguage::tryFrom((string) $request->query('lang', '')) ?? InvoiceLanguage::English;
    $pdf = WriteOffDocumentPdfGenerator::generate($writeOffDocument, $language);
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
