<?php

namespace App\Models;

use App\Enums\ReviewWindowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewWindow extends Model
{
    protected $fillable = [
        'title',
        'description',
        'starts_at',
        'ends_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => ReviewWindowStatus::class,
        ];
    }

    public function participations(): HasMany
    {
        return $this->hasMany(ReviewParticipation::class, 'review_window_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(ReviewResponse::class, 'review_window_id');
    }
}
