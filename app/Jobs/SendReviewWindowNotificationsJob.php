<?php

namespace App\Jobs;

use App\Models\ReviewWindow;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendReviewWindowNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ReviewWindow $reviewWindow;

    /**
     * Create a new job instance.
     */
    public function __construct(ReviewWindow $reviewWindow)
    {
        $this->reviewWindow = $reviewWindow;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $window = $this->reviewWindow;
        if (! $window->isActive()) {
            return;
        }

        // Get student user IDs to notify
        // If snapshot roster exists, notify those enrolled students; else fallback to all active students
        $studentIds = $window->roster()->pluck('student_id')->unique();
        if ($studentIds->isEmpty()) {
            $studentIds = User::where('role', 'student')
                ->where('is_active', true)
                ->pluck('id');
        }

        $title = 'Review window is now open';
        $body = "'{$window->title}' is now open. Submit your confidential course evaluations before it closes on {$window->ends_at->toFormattedDateString()}.";
        $data = ['review_window_id' => $window->id, 'route' => '/courses'];

        // 1. Bulk insert in-app notification rows in chunks of 200
        $insertedCount = NotificationService::bulkInsertInApp(
            $studentIds,
            'window',
            $title,
            $body,
            $data,
            chunkSize: 200
        );

        Log::info("Dispatched {$insertedCount} in-app notifications for Review Window #{$window->id}");

        // 2. Best-effort email notifications if SMTP is configured
        if (config('mail.from.address')) {
            $students = User::whereIn('id', $studentIds)
                ->whereNotNull('email')
                ->select(['id', 'email', 'name'])
                ->get();

            foreach ($students as $student) {
                if (! filter_var($student->email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                try {
                    Mail::raw($body, function ($message) use ($student, $title) {
                        $message->to($student->email)->subject("[FASRE] {$title}");
                    });
                } catch (Throwable $e) {
                    Log::warning("Failed to deliver review window email to {$student->email}: {$e->getMessage()}");
                }
            }
        }
    }
}
