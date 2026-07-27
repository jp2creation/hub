<?php

namespace Modules\CrmCore\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\CrmCore\Http\Requests\HubAssistantMessageRequest;
use Modules\CrmCore\Services\HubAssistantService;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class HubAssistantController extends Controller
{
    public function __invoke(HubAssistantMessageRequest $request, HubAssistantService $assistant): JsonResponse
    {
        try {
            $user = $this->authenticatedApiUser($request);

            if (! $user) {
                return $this->json(['ok' => false, 'error' => 'Utilisateur HUB requis'], 401);
            }

            $actor = $assistant->actorForUser($user);

            return $this->json($assistant->reply($actor, $request->assistantMessage()));
        } catch (HttpExceptionInterface $error) {
            return $this->json(['ok' => false, 'error' => $error->getMessage()], $error->getStatusCode());
        } catch (Throwable $error) {
            Log::error('[hub-assistant] '.$error->getMessage(), ['exception' => $error]);

            return $this->json([
                'ok' => false,
                'error' => config('app.debug') ? $error->getMessage() : 'Erreur assistant HUB',
            ], 500);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()
            ->json($data, $status, [], JSON_UNESCAPED_UNICODE)
            ->withHeaders($this->crmApiHeaders());
    }
}
