<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'group_by_year',
        'periodical_signature_needed',
        'periodical_signature_months',
        'confirmation_attachment_required',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'group_by_year' => 'boolean',
            'periodical_signature_needed' => 'boolean',
            'periodical_signature_months' => 'integer',
            'confirmation_attachment_required' => 'boolean',
        ];
    }

    /**
     * Periodical signature is derived from months:
     * - 0 => not needed
     * - >0 => needed every N months
     */
    public function setPeriodicalSignatureMonthsAttribute(mixed $value): void
    {
        $value = (int) ($value ?? 0);

        if ($value < 0) {
            $value = 0;
        }

        $this->attributes['periodical_signature_months'] = $value;
        $this->attributes['periodical_signature_needed'] = $value > 0;

        if ($value === 0) {
            $this->attributes['confirmation_attachment_required'] = false;
        }
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
