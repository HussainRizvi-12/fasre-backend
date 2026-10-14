<?php

namespace Tests\Feature;

use App\Models\AuditProvenanceLog;
use App\Models\ReviewWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewRosterMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_freezes_historic_empty_activations_and_preserves_legacy_fallback(): void
    {
        $migration = require database_path('migrations/2026_10_13_000001_preserve_empty_review_roster_snapshots.php');
        $migration->down();
        $attributes = ['title' => 'Migration probe', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'status' => 'closed'];
        $activated = ReviewWindow::create($attributes);
        $legacy = ReviewWindow::create($attributes);
        AuditProvenanceLog::record($activated, 'activated', null, 'Empty roster snapshot');
        $migration->up();
        $this->assertTrue($activated->fresh()->roster_is_frozen);
        $this->assertFalse($legacy->fresh()->roster_is_frozen);
        $this->assertSame(0, $activated->rosterEntries()->count());
    }
}
