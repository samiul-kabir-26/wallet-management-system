<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| TEMPORARY development inspection pages — remove before committing/pushing
|--------------------------------------------------------------------------
| To remove: delete this file, resources/views/dev/, and the require line at
| the bottom of routes/web.php.
|
| Loaded only when APP_ENV=local, so they 404 everywhere else even if forgotten.
*/

use App\Models\OtpToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

Route::prefix('dev')->group(function (): void {
    // Every OTP in the database, newest first (login, password reset, set PIN, phone codes)
    Route::get('/otps', function () {
        $otps = OtpToken::with('user:id,name,email,phone_number')
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(function (OtpToken $otp): array {
                $status = match (true) {
                    $otp->used_at !== null => 'used',
                    $otp->attempt_count >= $otp->max_attempts => 'locked',
                    $otp->expires_at->isPast() => 'expired',
                    default => 'active',
                };

                $sentTo = $otp->target
                    ?? (in_array($otp->purpose, ['PHONE_VERIFICATION'], true) ? $otp->user?->phone_number : $otp->user?->email);

                return [
                    'id' => $otp->id,
                    'code' => $otp->otp_code,
                    'purpose' => $otp->purpose,
                    'user' => $otp->user ? "{$otp->user->name} (#{$otp->user->id})" : '—',
                    'sent_to' => $sentTo ?? '—',
                    'status' => $status,
                    'attempts' => "{$otp->attempt_count} / {$otp->max_attempts}",
                    'created_at' => $otp->created_at,
                    'expires_at' => $otp->expires_at,
                ];
            });

        return view('dev.otps', ['otps' => $otps]);
    })->name('dev.otps');

    // Invitation and PIN reset links found in emails written to the log (MAIL_MAILER=log)
    Route::get('/email-links', function () {
        $path = storage_path('logs/laravel.log');
        $links = [];

        if (is_readable($path)) {
            $handle = fopen($path, 'r');
            $current = null;

            while (($line = fgets($handle)) !== false) {
                // A logged email starts with "[date] local.DEBUG: From: ..." (test runs log as "testing" and are skipped)
                if (preg_match('/^\[([^\]]+)\] local\.DEBUG: From: /', $line, $match)) {
                    $current = ['logged_at' => $match[1], 'to' => null, 'subject' => null, 'temporary_password' => null];

                    continue;
                }

                if ($current === null) {
                    continue;
                }

                if ($current['to'] === null && str_starts_with($line, 'To: ')) {
                    $current['to'] = trim(substr($line, 4));
                } elseif ($current['subject'] === null && str_starts_with($line, 'Subject: ')) {
                    $current['subject'] = trim(substr($line, 9));
                } elseif (preg_match('/^Temporary password: (.+)$/', $line, $match)) {
                    $current['temporary_password'] = trim($match[1]);
                } elseif (preg_match('/^(Accept invitation|Set a new PIN): (https?:\/\/\S+)/', $line, $match)) {
                    $emailedUrl = $match[2];
                    // Emails sent before the web pages existed linked to the API route; open the page instead
                    $pageUrl = str_replace(['/api/v1/auth/accept-invite/', '/api/v1/auth/reset-pin/'], ['/accept-invite/', '/reset-pin/'], $emailedUrl);
                    parse_str((string) parse_url($emailedUrl, PHP_URL_QUERY), $query);
                    $expires = isset($query['expires']) ? Carbon::createFromTimestamp((int) $query['expires']) : null;

                    $links[] = $current + [
                        'kind' => $match[1] === 'Accept invitation' ? 'Staff invitation' : 'PIN reset',
                        'page_url' => $pageUrl,
                        'emailed_url' => $emailedUrl,
                        'expires_at' => $expires,
                        'expired' => $expires?->isPast() ?? false,
                    ];
                    $current = null;
                }
            }

            fclose($handle);
        }

        return view('dev.email-links', ['links' => array_reverse($links)]);
    })->name('dev.email-links');
});
