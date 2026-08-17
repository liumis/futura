<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WriteOffSetting extends Model
{
    public static function instance(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public function signatories(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'write_off_setting_user')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    /**
     * @param  list<int|string|null>  $userIds
     */
    public function syncSignatories(array $userIds): void
    {
        $payload = collect($userIds)
            ->filter(fn (mixed $id): bool => filled($id))
            ->values()
            ->mapWithKeys(fn (mixed $id, int $index): array => [(int) $id => ['sort_order' => $index]])
            ->all();

        $this->signatories()->sync($payload);
    }
}
