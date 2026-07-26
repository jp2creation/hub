<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureCrmMobileTokenScope
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        if (! $request->bearerToken()) {
            if ($request->is('api/mobile/*')) {
                return $this->json('Bearer Token mobile requis.', 401);
            }

            return $next($request);
        }

        $user = Auth::guard('sanctum')->user();

        if (! $user instanceof User || ! $user->currentAccessToken()) {
            return $this->json('Token mobile invalide ou expire.', 401);
        }

        $request->setUserResolver(fn (): User => $user);

        if (! $this->tokenCanAny($user, 'hub:mobile')) {
            return $this->json('Token mobile non autorise pour le HUB.', 403);
        }

        if ($abilities !== []) {
            foreach ($abilities as $ability) {
                if ($this->tokenCanAny($user, $ability)) {
                    return $next($request);
                }
            }

            return $this->json('Scope mobile insuffisant.', 403);
        }

        return $next($request);
    }

    private function tokenCanAny(User $user, string $ability): bool
    {
        foreach ($this->equivalentAbilities($ability) as $equivalentAbility) {
            if ($user->tokenCan($equivalentAbility)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function equivalentAbilities(string $ability): array
    {
        if (Str::startsWith($ability, 'hub:')) {
            return [$ability, 'crm:'.Str::after($ability, 'hub:')];
        }

        if (Str::startsWith($ability, 'crm:')) {
            return [$ability, 'hub:'.Str::after($ability, 'crm:')];
        }

        return [$ability];
    }

    private function json(string $message, int $status): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => $message,
        ], $status, [], JSON_UNESCAPED_UNICODE);
    }
}
