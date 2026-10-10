<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectType extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    public function stages(): HasMany
    {
        return $this->hasMany(ProjectStage::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
