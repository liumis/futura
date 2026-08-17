<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class DocumentIntegrityChecker
{
    /**
     * @return array{
     *     hash: string,
     *     valid: bool,
     *     matches: Collection<int, array{document: Document, matched_as: string}>
     * }
     */
    public static function verifyFromDisk(string $disk, string $path): array
    {
        if (! Storage::disk($disk)->exists($path)) {
            throw new \RuntimeException('Uploaded file is missing.');
        }

        $binary = Storage::disk($disk)->get($path);
        $hash = hash('sha256', $binary);

        return self::verifyHash($hash);
    }

    /**
     * @return array{
     *     hash: string,
     *     valid: bool,
     *     matches: Collection<int, array{document: Document, matched_as: string}>
     * }
     */
    public static function verifyHash(string $hash): array
    {
        $hash = strtolower(trim($hash));

        $documents = Document::query()
            ->with(['documentType', 'approvedBy', 'uploadedBy'])
            ->where(function ($query) use ($hash): void {
                $query
                    ->where('content_hash', $hash)
                    ->orWhere('pdf_hash', $hash);
            })
            ->orderByDesc('approval_date')
            ->orderByDesc('id')
            ->get();

        $matches = $documents->map(function (Document $document) use ($hash): array {
            $matchedAs = [];

            if (strtolower((string) $document->content_hash) === $hash) {
                $matchedAs[] = 'Original content';
            }

            if (strtolower((string) $document->pdf_hash) === $hash) {
                $matchedAs[] = 'Approved PDF';
            }

            return [
                'document' => $document,
                'matched_as' => implode(', ', $matchedAs) ?: 'hash',
            ];
        });

        return [
            'hash' => $hash,
            'valid' => $matches->isNotEmpty(),
            'matches' => $matches,
        ];
    }
}
