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
