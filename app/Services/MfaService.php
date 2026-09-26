<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class MfaService
{
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a cryptographically secure random Base32 secret key.
     */
    public static function generateSecret(int $length = 32): string
    {
        $secret = '';
        $max = strlen(self::BASE32_CHARS) - 1;
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_CHARS[random_int(0, $max)];
        }

        return $secret;
    }

    /**
     * Decode a Base32 string to binary bytes according to RFC 3548 / RFC 4648.
     */
    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(trim($b32));
        $binary = '';
        $len = strlen($b32);

        for ($i = 0; $i < $len; $i++) {
            $char = $b32[$i];
            if ($char === '=') {
                break;
            }
            $pos = strpos(self::BASE32_CHARS, $char);
            if ($pos === false) {
                continue;
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        $chunks = str_split($binary, 8);
        foreach ($chunks as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }

    /**
     * Calculate a 6-digit TOTP code for a given secret and timestamp (RFC 6238).
     */
    public static function calculateTotp(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $timeSlice = (int) floor($timestamp / 30);
        $binarySecret = self::base32Decode($secret);

        // 64-bit integer packed big-endian (network byte order)
        $time = pack('N*', 0, $timeSlice);
        $hash = hash_hmac('sha1', $time, $binarySecret, true);

        $offset = ord($hash[19]) & 0x0f;
        $code = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        $otp = $code % 1000000;

        return str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a 6-digit TOTP code against a secret within a drift window.
     */
    public static function verifyTotp(string $secret, string $code, int $discrepancy = 1): bool
    {
        $currentTime = time();
        $code = trim($code);

        if (strlen($code) !== 6 || ! ctype_digit($code)) {
            return false;
        }

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculated = self::calculateTotp($secret, $currentTime + ($i * 30));
            if (hash_equals($calculated, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate formatted single-use recovery codes.
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(4)));
            $codes[] = substr($raw, 0, 4).'-'.substr($raw, 4, 4);
        }

        return $codes;
    }

    /**
     * Verify and consume a single-use recovery code atomically inside a transaction.
     */
    public static function verifyAndConsumeRecoveryCode(User $user, string $code): bool
    {
        $code = strtoupper(trim($code));

        return \Illuminate\Support\Facades\DB::transaction(function () use ($user, $code) {
            /** @var User|null $freshUser */
            $freshUser = User::where('id', $user->id)->lockForUpdate()->first();

            if (! $freshUser || ! $freshUser->is_active) {
                return false;
            }

            $stored = $freshUser->mfa_recovery_codes ?? [];

            if (! is_array($stored) || empty($stored)) {
                return false;
            }

            foreach ($stored as $index => $hashedCode) {
                if (Hash::check($code, $hashedCode)) {
                    array_splice($stored, $index, 1);
                    $freshUser->update(['mfa_recovery_codes' => $stored]);
                    $user->mfa_recovery_codes = $stored;

                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Generate the standard otpauth:// provisioning URI for QR code generation.
     */
    public static function getProvisioningUri(string $secret, string $email, string $issuer = 'FASRE'): string
    {
        $encodedIssuer = rawurlencode($issuer);
        $encodedEmail = rawurlencode($email);

        return "otpauth://totp/{$encodedIssuer}:{$encodedEmail}?secret={$secret}&issuer={$encodedIssuer}&algorithm=SHA1&digits=6&period=30";
    }
}
