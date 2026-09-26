<?php

namespace App\Models;

use App\Enums\FormType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormVersion extends Model
{
    protected $fillable = [
        'form_type',
        'version_code',
        'title',
        'description',
        'questions_json',
        'scoring_rules_json',
        'is_published',
        'is_legacy_reconstruction',
        'created_by',
    ];

    protected $casts = [
        'questions_json' => 'array',
        'scoring_rules_json' => 'array',
        'is_published' => 'boolean',
        'is_legacy_reconstruction' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewWindows(): HasMany
    {
        return $this->hasMany(ReviewWindow::class);
    }

    public function auditAssignments(): HasMany
    {
        return $this->hasMany(AuditAssignment::class);
    }

    /**
     * Get questions array, sorted by sort_order.
     */
    public function getQuestions(): array
    {
        $questions = $this->questions_json ?? [];
        usort($questions, fn ($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));
        return $questions;
    }

    /**
     * Finds question definition by its integer or string ID.
     */
    public function findQuestion(int|string $questionId): ?array
    {
        foreach ($this->questions_json ?? [] as $q) {
            if ((string) ($q['id'] ?? '') === (string) $questionId) {
                return $q;
            }
        }
        return null;
    }
}
