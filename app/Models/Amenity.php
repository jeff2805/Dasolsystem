<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A resort-owned amenity entry.
 *
 * Each amenity row is attached to one resort through amenity_resort.
 * The same label may exist independently for different resorts.
 *
 * icon is a Bootstrap Icons name without the "bi-" prefix.
 */
class Amenity extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'icon',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function resorts(): BelongsToMany
    {
        return $this->belongsToMany(Resort::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
