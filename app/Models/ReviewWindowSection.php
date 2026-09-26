<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewWindowSection extends Model
{
    protected $fillable = [
        'review_window_id',
        'section_id',
    ];

    public function reviewWindow(): BelongsTo
    {
        return $this->belongsTo(ReviewWindow::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
}
