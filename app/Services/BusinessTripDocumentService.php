<?php

namespace App\Services;

use App\Models\BusinessTripRequest;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class BusinessTripDocumentService
{
    public const DOCUMENT_TYPE_NAME = 'Komandiruotė';

    public static function ensureDocumentTypeExists(): DocumentType
    {
        return DocumentType::query()->firstOrCreate(
            ['name' => self::DOCUMENT_TYPE_NAME],
            ['group_by_year' => true],
        );
    }

    /**
     * @param  list<string>  $localPaths  Paths on the public disk
     */
    public static function attachUploads(BusinessTripRequest $trip, array $localPaths, User $uploader): Document
    {
        $localPaths = array_values(array_filter($localPaths, fn ($path): bool => filled($path)));
        if ($localPaths === []) {
            throw new RuntimeException('No files selected to upload.');
        }

        $trip->loadMissing('employee');
        $type = self::ensureDocumentTypeExists();
        $documentDate = $trip->date_to?->toDateString() ?? $trip->date_from?->toDateString() ?? now()->toDateString();

        $document = Document::query()->create([
            'document_date' => $documentDate,
            'name' => self::documentName($trip),
            'document_type_id' => $type->getKey(),
            'file_path' => null,
            'user_uploaded_id' => $uploader->getKey(),
            'flag_approved' => true,
            'user_approved_id' => $uploader->getKey(),
            'approval_date' => now(),
        ]);

        try {
            DocumentBinaryStore::ingestLocalUploads($document->fresh(['documentType']), $localPaths, allowWhenLocked: true);
        } catch (Throwable $exception) {
            Log::warning('Business trip document SharePoint upload failed', [
                'business_trip_request_id' => $trip->getKey(),
                'document_id' => $document->getKey(),
                'message' => $exception->getMessage(),
            ]);

            $document->forceDelete();

            throw new RuntimeException(
                'Business trip document storage failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $trip->documents()->syncWithoutDetaching([$document->getKey()]);

        foreach ($localPaths as $path) {
            if (filled($path) && Storage::disk('public')->exists((string) $path)) {
                Storage::disk('public')->delete((string) $path);
            }
        }

        // Auto-fill other expenses from newly attached expense-report PDFs when empty.
        try {
            BusinessTripExpensesExtractor::applyToTrip($trip->fresh(['documents']), overwrite: false);
        } catch (Throwable $exception) {
            Log::warning('Business trip expenses auto-fill after upload failed', [
                'business_trip_request_id' => $trip->getKey(),
                'message' => $exception->getMessage(),
            ]);
        }

        return $document->fresh(['documentType']);
    }

    public static function documentName(BusinessTripRequest $trip): string
    {
        $employee = $trip->employee?->fullName() ?: 'Employee';
        $from = optional($trip->date_from)->format('Y-m-d') ?? '';
        $to = optional($trip->date_to)->format('Y-m-d') ?? '';
        $range = $from === $to || $to === ''
            ? $from
            : $from.'–'.$to;

        return trim('Komandiruotė — '.$employee.($range !== '' ? ' — '.$range : ''));
    }

    /**
     * @param  mixed  $state
     * @return list<string>
     */
    public static function normalizeUploadPaths(mixed $state): array
    {
        if ($state === null || $state === '' || $state === []) {
            return [];
        }

        $items = is_array($state) ? $state : [$state];
        $paths = [];

        foreach ($items as $item) {
            $path = self::normalizeUploadPath($item);
            if ($path !== null) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    public static function normalizeUploadPath(mixed $state): ?string
    {
        if ($state === null || $state === '') {
            return null;
        }

        if (is_array($state)) {
            $state = $state[0] ?? null;
        }

        if ($state === null || $state === '') {
            return null;
        }

        if (is_object($state) && method_exists($state, 'getPathname')) {
            /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile $state */
            $stored = $state->store('business-trip-uploads', 'public');

            return is_string($stored) && $stored !== '' ? $stored : null;
        }

        $path = ltrim(str_replace('\\', '/', (string) $state), '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return $path !== '' ? $path : null;
    }
}
