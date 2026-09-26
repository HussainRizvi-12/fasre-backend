<?php

use App\Enums\ReviewWindowStatus;
use App\Models\AuditProvenanceLog;
use App\Models\ReviewWindow;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Automatically check and close active review windows that have passed their ends_at date.
 * Ensures cycles close promptly even if administrators do not manually trigger closure.
 */
Artisan::command('fasre:close-expired-windows', function () {
    $now = now();
    $expiredWindows = ReviewWindow::where('status', ReviewWindowStatus::Active)
        ->where('ends_at', '<=', $now)
        ->get();

    $closedCount = 0;
    foreach ($expiredWindows as $window) {
        DB::transaction(function () use ($window, &$closedCount) {
            $locked = ReviewWindow::whereKey($window->id)->lockForUpdate()->first();
            if ($locked && $locked->status === ReviewWindowStatus::Active && $locked->ends_at <= now()) {
                $locked->status = ReviewWindowStatus::Closed;
                $locked->save();

                ActivityLogger::log($locked, 'review_window.auto_closed', ['title' => $locked->title]);
                AuditProvenanceLog::record(
                    $locked,
                    'auto_closed',
                    null,
                    'Review window automatically closed upon expiration of submission date range.',
                    ['status' => 'active'],
                    ['status' => 'closed', 'closed_at' => now()->toIso8601String()]
                );
                $closedCount++;
            }
        });
    }

    $this->info("Closed {$closedCount} expired review window(s).");
})->purpose('Automatically close active review windows that have passed their ends_at date');

Schedule::command('fasre:close-expired-windows')->everyMinute();
