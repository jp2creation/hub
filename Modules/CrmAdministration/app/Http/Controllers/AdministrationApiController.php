<?php

namespace Modules\CrmAdministration\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\CrmAdministration\Services\AdministrationService;
use Modules\CrmCore\Http\Requests\CrmApiRequest;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AdministrationApiController extends Controller
{
    public function __invoke(CrmApiRequest $request, AdministrationService $administration): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->json(['ok' => true]);
        }

        try {
            $action = $request->action();

            if ($action === 'health') {
                return $this->json(['ok' => true, 'mode' => 'laravel']);
            }

            $administration->ensureDefaults();

            $user = $this->authenticatedApiUser($request);
            if (! $user) {
                return $this->json(['ok' => false, 'error' => 'Utilisateur HUB requis'], 401);
            }

            $actor = $administration->actorForUser($user);
            $body = $request->body();

            return match ($action) {
                'profile' => $this->json($administration->profile($actor)),
                'save_profile' => $this->json($administration->saveProfile($actor, $body)),
                'delete_session' => $this->json($administration->deleteSession($actor, $body)),
                'bootstrap' => $this->json($administration->bootstrap($actor)),
                'save_menu_settings' => $this->json($administration->saveMenuSettings($actor, $body)),
                'pages_bootstrap' => $this->json($administration->pagesBootstrap($actor)),
                'save_page' => $this->json($administration->savePage($actor, $body)),
                'delete_page' => $this->json($administration->deletePage($actor, $body)),
                'save_site' => $this->json($administration->saveSite($actor, $body)),
                'delete_site' => $this->json($administration->deleteSite($actor, $body)),
                'save_module' => $this->json($administration->saveModule($actor, $body)),
                'save_user' => $this->json($administration->saveUser($actor, $body)),
                default => $this->json(['ok' => false, 'error' => 'Action inconnue'], 404),
            };
        } catch (HttpExceptionInterface $error) {
            return $this->json(['ok' => false, 'error' => $error->getMessage()], $error->getStatusCode());
        } catch (Throwable $error) {
            Log::error('[crm-administration] '.$error->getMessage(), ['exception' => $error]);

            return $this->json([
                'ok' => false,
                'error' => config('app.debug') ? $error->getMessage() : 'Erreur API administration',
            ], 500);
        }
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()
            ->json($data, $status, [], JSON_UNESCAPED_UNICODE)
            ->withHeaders($this->crmApiHeaders());
    }
}
