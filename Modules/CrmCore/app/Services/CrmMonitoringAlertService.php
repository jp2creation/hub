<?php

namespace Modules\CrmCore\Services;

use App\Models\CrmNotificationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CrmMonitoringAlertService
{
    public function recordFailedLogin(Request $request, string $email): void
    {
        if (! Schema::hasTable('notification_logs')) {
            return;
        }

        $email = Str::lower(trim($email));
        $ip = (string) $request->ip();
        $fingerprint = hash('sha256', $email.'|'.$ip);
        $threshold = max(1, (int) config('crm.monitoring.failed_login_threshold', 5));
        $windowMinutes = max(1, (int) config('crm.monitoring.failed_login_window_minutes', 15));
        $cooldownMinutes = max(1, (int) config('crm.monitoring.failed_login_cooldown_minutes', 30));
        $windowKey = 'hub:monitoring:failed-login:'.$fingerprint.':window';
        $cooldownKey = 'hub:monitoring:failed-login:'.$fingerprint.':cooldown';

        Cache::add($windowKey, 0, now()->addMinutes($windowMinutes));

        $attempts = (int) Cache::increment($windowKey);
        if ($attempts < $threshold || ! Cache::add($cooldownKey, true, now()->addMinutes($cooldownMinutes))) {
            return;
        }

        CrmNotificationLog::query()->create([
            'channel' => 'monitoring',
            'recipient' => 'auth:login',
            'subject' => 'Alerte connexions echouees',
            'template_key' => 'auth.login.failed',
            'locale' => (string) config('crm.notifications.locale', 'fr'),
            'status' => CrmNotificationLog::STATUS_SENT,
            'payload' => [
                'attempts' => $attempts,
                'threshold' => $threshold,
                'windowMinutes' => $windowMinutes,
                'cooldownMinutes' => $cooldownMinutes,
                'ip' => $ip,
                'emailHash' => hash('sha256', $email),
                'emailHint' => $this->emailHint($email),
                'userAgent' => Str::limit((string) $request->userAgent(), 255),
            ],
            'sent_at' => now(),
        ]);
    }

    private function emailHint(string $email): string
    {
        if (! str_contains($email, '@')) {
            return Str::mask($email, '*', 2);
        }

        [$local, $domain] = explode('@', $email, 2);

        return Str::mask($local, '*', 2).'@'.$domain;
    }
}
