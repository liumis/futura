<?php

namespace App\Models;

use App\Services\UtfMojibakeRepairer;
use App\Support\ExtractedTextNormalizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirectoryContactComment extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'directory_contact_id',
        'user_id',
        'commented_at',
        'comment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'commented_at' => 'datetime',
        ];
    }

    /**
     * Repair CP437/OEM mojibake (d─ùl → dėl) on write so board/Excel saves stay UTF-8.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function comment(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                if ($value === null) {
                    return null;
                }

                $value = trim($value);
                if ($value === '' || ! ExtractedTextNormalizer::looksCorrupted($value)) {
                    return $value;
                }

                return UtfMojibakeRepairer::repairValue($value) ?? $value;
            },
        );
    }

    public function directoryContact(): BelongsTo
    {
        return $this->belongsTo(DirectoryContact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
