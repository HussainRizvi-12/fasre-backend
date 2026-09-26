<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewWindowRoster extends Model
{
    protected $fillable = [
        'review_window_id',
        'section_id',
        'student_id',
        'status', // eligible, withdrawn, added
        'reason',
        'updated_by',
    ];

    public function reviewWindow(): BelongsTo
    {
        return $this->belongsTo(ReviewWindow::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
