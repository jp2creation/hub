<?php

namespace Modules\CrmCore\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\CrmCore\Http\Requests\MobileTokenRequest;
use Modules\CrmCore\Services\MobileAuthService;

class MobileAuthController extends Controller
{
    public function options(): JsonResponse
    {
        return $this->json(['ok' => true]);
    }

    public function token(MobileTokenRequest $request, MobileAuthService $mobileAuth): JsonResponse
    {
        return $this->jsonResult($mobileAuth->issueToken($request));
    }

    public function refresh(Request $request, MobileAuthService $mobileAuth): JsonResponse
    {
        return $this->jsonResult($mobileAuth->refreshToken($request));
    }

    public function me(Request $request, MobileAuthService $mobileAuth): JsonResponse
    {
        return $this->jsonResult($mobileAuth->currentUser($request));
    }

    public function nativeSession(Request $request, MobileAuthService $mobileAuth): JsonResponse
    {
        return $this->jsonResult($mobileAuth->issueTokenForWebSession($request));
    }

    public function webSession(Request $request, MobileAuthService $mobileAuth): JsonResponse
    {
        return $this->jsonResult($mobileAuth->createWebSession($request));
    }

    public function consumeWebSession(Request $request, string $token, MobileAuthService $mobileAuth): RedirectResponse
    {
        return redirect()->to($mobileAuth->consumeWebSession($request, $token));
    }

    public function logout(Request $request, MobileAuthService $mobileAuth): JsonResponse
    {
        $mobileAuth->revokeCurrentToken($request);

        return $this->json(['ok' => true]);
    }

    /**
     * @param  array{data: array<string, mixed>, status: int}  $result
     */
    private function jsonResult(array $result): JsonResponse
    {
        return $this->json($result['data'], $result['status']);
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()
            ->json($data, $status, [], JSON_UNESCAPED_UNICODE)
            ->withHeaders($this->crmApiHeaders());
    }
}
