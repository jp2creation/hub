<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        if (! $user->tokenCan('crm:mobile')) {
            return $this->json('Token mobile non autorise pour le HUB.', 403);
        }

        if ($abilities !== []) {
            foreach ($abilities as $ability) {
                if ($user->tokenCan($ability)) {
                    return $next($request);
                }
            }

            return $this->json('Scope mobile insuffisant.', 403);
        }

        return $next($request);
    }

    private function json(string $message, int $status): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => $message,
        ], $status, [], JSON_UNESCAPED_UNICODE);
    }
}
