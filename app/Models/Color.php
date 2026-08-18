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

    public function imageUrl(): ?string
    {
        if (blank($this->image)) {
            return null;
        }

        // On production we usually store uploads on S3 (FILESYSTEM_DISK=s3).
        // On local we typically use the "public" disk (storage/app/public + storage:link).
        $filesystemDisk = (string) env('FILESYSTEM_DISK', config('filesystems.default', 'local'));
        $disk = $filesystemDisk === 's3' ? 's3' : 'public';

        return Storage::disk($disk)->url($this->image);
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
