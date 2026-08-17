<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use setasign\Fpdi\Fpdi;

class DocumentApprover
{
    public static function approve(Document $document, User $approver, string $ip, string $userAgent): Document
    {
        if ($document->flag_approved) {
            throw new \RuntimeException('Document is already approved and locked.');
        }

        if (! DocumentBinaryStore::hasFile($document)) {
            throw new \RuntimeException('Document file is missing.');
        }

        $binary = DocumentBinaryStore::getBinary($document);
        $contentHash = hash('sha256', $binary);
        $isPdf = DocumentBinaryStore::isPdf($document, $binary);

        $approvedBinary = null;
        $approvedFileName = null;

        if ($isPdf) {
            try {
                $approvedBinary = self::stampPdfBinary($binary, $approver);
                $approvedFileName = self::approvedFileName($document, 'pdf');
            } catch (\Throwable) {
                $approvedBinary = self::approvalCertificateBinary($document, $approver, $ip, $userAgent, $contentHash);
                $approvedFileName = self::approvedFileName($document, 'approval.pdf');
            }
        } else {
            $approvedBinary = self::approvalCertificateBinary($document, $approver, $ip, $userAgent, $contentHash);
            $approvedFileName = self::approvedFileName($document, 'approval.pdf');
        }

        $pdfHash = hash('sha256', $approvedBinary);
        DocumentBinaryStore::storeBinary($document->fresh(['documentType']), $approvedBinary, $approvedFileName);

        $document->update([
            'flag_approved' => true,
            'user_approved_id' => $approver->id,
            'approval_date' => now(),
            'confirmed_ip' => $ip,
            'confirmed_user_agent' => $userAgent,
            'content_hash' => $contentHash,
            'pdf_hash' => $pdfHash,
            'approved_file_path' => null,
            'file_path' => null,
        ]);

        return $document->fresh(['uploadedBy', 'approvedBy', 'documentType']);
    }

    private static function approvedFileName(Document $document, string $suffix): string
    {
        $base = filled($document->name) ? (string) $document->name : 'document';
        $base = SharepointGraphClient::make()->sanitizeFileName($base);
        $base = pathinfo($base, PATHINFO_FILENAME) ?: 'document';

        if ($suffix === 'pdf') {
            return $base.'-approved.pdf';
        }

        return $base.'-'.$suffix;
    }

    private static function stampPdfBinary(string $binary, User $approver): string
    {
        $tempOriginal = tempnam(sys_get_temp_dir(), 'doc_src_');
        $tempOutput = tempnam(sys_get_temp_dir(), 'doc_out_');

        if ($tempOriginal === false || $tempOutput === false) {
            throw new \RuntimeException('Could not create temporary files for PDF approval.');
        }

        $sourcePath = $tempOriginal.'.pdf';
        $outputPath = $tempOutput.'.pdf';
        @unlink($tempOriginal);
        @unlink($tempOutput);

        file_put_contents($sourcePath, $binary);

        try {
            $pdf = new Fpdi();
            $pageCount = $pdf->setSourceFile($sourcePath);

            for ($page = 1; $page <= $pageCount; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                $pdf->useTemplate($template);

                if ($page === 1) {
                    self::drawSignatureStamp($pdf, $approver);
                }
            }

            $pdf->Output('F', $outputPath);
            $stamped = file_get_contents($outputPath);

            if ($stamped === false) {
                throw new \RuntimeException('Failed to read stamped PDF.');
            }

            return $stamped;
        } finally {
            @unlink($sourcePath);
            @unlink($outputPath);
        }
    }

    private static function drawSignatureStamp(Fpdi $pdf, User $approver): void
    {
        $x = 7;
        $y = 7;
        $w = 52;
        $h = 18;
        $pad = 2;

        $pdf->SetDrawColor(170, 170, 170);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetLineWidth(0.25);
        $pdf->Rect($x, $y, $w, $h, 'DF');

        $pdf->SetTextColor(45, 45, 45);

        $pdf->SetXY($x + $pad, $y + $pad);
        $pdf->SetFont('Helvetica', 'B', 4.5);
        $pdf->Cell($w - (2 * $pad), 2.6, self::pdfText('Document approval'), 0, 1, 'L');

        $pdf->SetX($x + $pad);
        $pdf->SetFont('Helvetica', 'B', 7);
        $name = mb_strtoupper(self::personName($approver), 'UTF-8');
        $pdf->Cell($w - (2 * $pad), 3.8, self::pdfText($name), 0, 1, 'L');

        $pdf->SetX($x + $pad);
        $pdf->SetFont('Helvetica', '', 5);
        $pdf->Cell($w - (2 * $pad), 2.8, self::pdfText(self::approvalTimestamp()), 0, 1, 'L');

        $pdf->SetX($x + $pad);
        $pdf->SetFont('Helvetica', '', 4.5);
        $pdf->Cell($w - (2 * $pad), 2.6, self::pdfText('Purpose: Approval'), 0, 1, 'L');
    }

    private static function approvalTimestamp(): string
    {
        $hours = intdiv((int) now()->format('Z'), 3600);
        $gmt = 'GMT'.($hours >= 0 ? '+'.$hours : (string) $hours);

        return now()->format('Y-m-d H:i:s').' '.$gmt;
    }

    private static function approvalCertificateBinary(
        Document $document,
        User $approver,
        string $ip,
        string $userAgent,
        string $contentHash,
    ): string {
        $html = view('pdf.document-approval', [
            'document' => $document->loadMissing(['documentType', 'uploadedBy']),
            'approver' => $approver,
            'ip' => $ip,
            'userAgent' => $userAgent,
            'contentHash' => $contentHash,
            'approvedAt' => now(),
            'timestampLabel' => self::approvalTimestamp(),
            'approverName' => mb_strtoupper(self::personName($approver), 'UTF-8'),
        ])->render();

        return Pdf::loadHTML($html)->setPaper('a4', 'portrait')->output();
    }

    private static function personName(User $user): string
    {
        $fullName = $user->fullName();

        return $fullName !== '' ? $fullName : '—';
    }

    private static function pdfText(string $text): string
    {
        return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text) ?: $text;
    }
}
