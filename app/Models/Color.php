<?php

namespace App\Models;

use Database\Factories\ColorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Color extends Model
{
    /** @use HasFactory<ColorFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'collection_id',
        'color_code',
        'color_name',
        'image',
    ];

    public static function storageDisk(): string
    {
        $default = (string) config('filesystems.default', 'local');

        return $default === 's3' ? 's3' : 'public';
    }

    public function imageUrl(): ?string
    {
        if (blank($this->image)) {
            return null;
        }

        return Storage::disk(self::storageDisk())->url($this->image);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
