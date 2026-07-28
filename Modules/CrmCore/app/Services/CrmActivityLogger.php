<?php

namespace Modules\CrmCore\Services;

use App\Models\CrmUser;
use Illuminate\Support\Facades\DB;
use JsonException;

class CrmActivityLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function log(CrmUser $actor, string $action, string $details = '', array $context = []): void
    {
        $request = request();
        $userAgent = (string) $request->userAgent();

        DB::table('crm_logs')->insert([
            'user_id' => $actor->id,
            'user_name' => $actor->name,
            'action' => $action,
            'details' => $details,
            'created_at' => now(),
            'ip' => $request->ip() ?? '',
            'user_agent' => $userAgent !== '' ? mb_substr($userAgent, 0, 512) : null,
            'context' => $this->encodeContext($this->context($actor, $context)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function context(CrmUser $actor, array $context): array
    {
        $request = request();
        $payload = $this->sanitizedPayload($request->except(['_token', '_method']));

        return array_filter([
            'actor' => [
                'crmUserId' => (int) $actor->id,
                'accountId' => (int) $actor->id,
                'role' => $actor->role,
            ],
            'request' => [
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'route' => $request->route()?->getName(),
                'ip' => $request->ip(),
                'userAgent' => $request->userAgent(),
                'referer' => $request->headers->get('referer'),
            ],
            'payload' => $payload !== [] ? $payload : null,
            'changes' => isset($context['changes']) && is_array($context['changes'])
                ? $this->sanitizedPayload($context['changes'])
                : null,
            'meta' => ($meta = array_diff_key($context, ['changes' => true])) !== []
                ? $this->sanitizedPayload($meta)
                : null,
        ], fn ($value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizedPayload(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (preg_match('/password|token|secret|photo|image|file|attachment|signature/', $normalizedKey) === 1) {
                $sanitized[$key] = '[filtered]';

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizedPayload($value);

                continue;
            }

            if (is_string($value) && mb_strlen($value) > 500) {
                $sanitized[$key] = mb_substr($value, 0, 500).'...';

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function encodeContext(array $context): string
    {
        try {
            return json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return '{}';
        }
    }
}
