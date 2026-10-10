<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectStage extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'project_type_id',
    ];

    public function projectType(): BelongsTo
    {
        return $this->belongsTo(ProjectType::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
