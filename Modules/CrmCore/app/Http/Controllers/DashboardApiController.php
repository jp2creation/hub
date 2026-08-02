<?php

namespace Modules\CrmCore\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\CrmCore\Http\Requests\CrmApiRequest;
use Modules\CrmCore\Services\DashboardService;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class DashboardApiController extends Controller
{
    public function __invoke(CrmApiRequest $request, DashboardService $dashboard): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->json(['ok' => true]);
        }

        try {
            $action = $request->action('overview');

            if ($action === 'health') {
                return $this->json(['ok' => true, 'mode' => 'laravel']);
            }

            $user = $this->authenticatedApiUser($request);

            if (! $user) {
                return $this->json(['ok' => false, 'error' => 'Utilisateur HUB requis'], 401);
            }

            $actor = $dashboard->actorForUser($user);

            return match ($action) {
                'overview', 'bootstrap' => $this->json($dashboard->overview($actor, $request->siteId($request->body()))),
                'save_quick_access' => $request->isMethod('POST')
                    ? $this->json($dashboard->saveQuickAccess($actor, $request->body()))
                    : $this->json(['ok' => false, 'error' => 'Méthode invalide'], 405),
                default => $this->json(['ok' => false, 'error' => 'Action inconnue'], 404),
            };
        } catch (HttpExceptionInterface $error) {
            return $this->json(['ok' => false, 'error' => $error->getMessage()], $error->getStatusCode());
        } catch (Throwable $error) {
            Log::error('[crm-dashboard] '.$error->getMessage(), ['exception' => $error]);

            return $this->json([
                'ok' => false,
                'error' => config('app.debug') ? $error->getMessage() : 'Erreur API dashboard',
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
