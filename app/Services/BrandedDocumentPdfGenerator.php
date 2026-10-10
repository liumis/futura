<?php

namespace App\Services;

use App\Models\CompanySetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

class BrandedDocumentPdfGenerator
{
    public static function generate(string $name, mixed $documentDate, string $bodyHtml): string
    {
        return Pdf::loadView('pdf.branded-document', self::build($name, $documentDate, $bodyHtml))
            ->setPaper('a4', 'portrait')
            ->output();
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(string $name, mixed $documentDate, string $bodyHtml): array
    {
        $company = CompanySetting::instance();
        $date = filled($documentDate)
            ? Carbon::parse((string) $documentDate)
            : now();

        return [
            'logoDataUri' => self::logoDataUri(),
            'companyName' => (string) ($company->company_name ?: 'Įmonė'),
            'companyId' => (string) ($company->company_id ?? ''),
            'companyAddress' => (string) ($company->company_address ?? ''),
            'companyEmail' => (string) ($company->company_email ?? ''),
            'companyPhone' => (string) ($company->company_phone ?? ''),
            'documentName' => trim($name) !== '' ? trim($name) : 'Document',
            'documentDate' => $date->format('Y-m-d'),
            'bodyHtml' => self::sanitizeBody($bodyHtml),
        ];
    }

    public static function sanitizeBody(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $allowed = '<p><br><strong><b><em><i><u><s><ul><ol><li><h2><h3><h4><blockquote><a><table><thead><tbody><tr><th><td><span><div>';
        $clean = strip_tags($html, $allowed);
        $clean = preg_replace('/\son\w+\s*=\s*(["\']).*?\1/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/javascript\s*:/iu', '', $clean) ?? $clean;

        return $clean;
    }

    private static function logoDataUri(): ?string
    {
        $pngPath = public_path('images/logo-invoice.png');

        if (is_readable($pngPath)) {
            $contents = file_get_contents($pngPath);
            if ($contents !== false) {
                return 'data:image/png;base64,'.base64_encode($contents);
            }
        }

        return null;
    }
}
