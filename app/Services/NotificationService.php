<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Central notification service — creates in-app notifications and
 * (best-effort) sends matching email notifications.
 */
class NotificationService
{
    /**
     * Notify a single user.
     *
     * @param  array<string, mixed>  $data  Optional payload (routes, ids...)
     */
    public static function send(
        User $user,
        string $type,
        string $title,
        string $body,
        array $data = [],
        bool $email = true,
    ): AppNotification {
        $notification = AppNotification::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'data' => $data,
        ]);

        if ($email && filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            self::sendEmail($user, $title, $body);
        }

        return $notification;
    }

    /**
     * Notify many users (chunked inserts + batched email loop).
     *
     * @param  \Illuminate\Support\Collection<int, User>|iterable<User>  $users
     * @param  array<string, mixed>  $data
     */
    public static function sendMany(
        iterable $users,
        string $type,
        string $title,
        string $body,
        array $data = [],
        bool $email = false,
    ): int {
        $count = 0;
        foreach ($users as $user) {
            self::send($user, $type, $title, $body, $data, $email);
            $count++;
        }

        return $count;
    }

    /**
     * Efficiently bulk-inserts in-app notification rows in chunks.
     *
     * @param  iterable<int|string>  $userIds
     * @param  array<string, mixed>  $data
     */
    public static function bulkInsertInApp(
        iterable $userIds,
        string $type,
        string $title,
        string $body,
        array $data = [],
        int $chunkSize = 200
    ): int {
        $now = now();
        $payload = json_encode($data);
        $total = 0;
        $batch = [];

        foreach ($userIds as $userId) {
            $batch[] = [
                'user_id' => $userId,
                'title' => $title,
                'body' => $body,
                'type' => $type,
                'data' => $payload,
                'is_read' => false,
                'created_at' => $now,
            ];

            if (count($batch) >= $chunkSize) {
                AppNotification::insert($batch);
                $total += count($batch);
                $batch = [];
            }
        }

        if (! empty($batch)) {
            AppNotification::insert($batch);
            $total += count($batch);
        }

        return $total;
    }

    /**
     * Email is best-effort: in production without SMTP configured this must
     * never break the main flow — failures are logged and swallowed.
     */
    protected static function sendEmail(User $user, string $title, string $body): void
    {
        if (! config('mail.from.address')) {
            return;
        }

        try {
            Mail::raw(
                $body,
                function ($message) use ($user, $title) {
                    $message->to($user->email)->subject("[FASRE] {$title}");
                },
            );
        } catch (Throwable $e) {
            Log::warning('FASRE notification email failed: '.$e->getMessage());
        }
    }
}
