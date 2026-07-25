<?php

namespace Modules\CrmSales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\CrmCore\Http\Requests\CrmApiRequest;
use Modules\CrmSales\Services\SalesDashboardService;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class SalesDashboardApiController extends Controller
{
    public function __invoke(CrmApiRequest $request, SalesDashboardService $sales): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->json(['ok' => true]);
        }

        try {
            $action = $request->action();

            if ($action === 'health') {
                return $this->json(['ok' => true, 'mode' => 'mysql']);
            }

            $user = $this->authenticatedApiUser($request);
            if (! $user) {
                return $this->json(['ok' => false, 'error' => 'Utilisateur HUB requis'], 401);
            }

            $actor = $sales->actorForUser($user);
            $body = $request->body();
            $siteId = $request->siteId($body);

            return match ($action) {
                'bootstrap' => $this->json($sales->bootstrap($actor, $siteId, [
                    'month' => $request->query('month', $body['month'] ?? null),
                    'representativeId' => $request->query('representativeId', $body['representativeId'] ?? $body['representative_user_id'] ?? null),
                    'status' => $request->query('status', $body['status'] ?? null),
                ])),
                'sync_demo' => $this->json($sales->syncDemoInvoices($actor, $siteId, (string) ($body['month'] ?? $request->query('month', '')))),
                'save_objective' => $this->json($sales->saveObjective($actor, $body)),
                'mark_commission_paid' => $this->json($sales->markCommissionPaid($actor, $body)),
                default => $this->json(['ok' => false, 'error' => 'Action inconnue'], 404),
            };
        } catch (HttpExceptionInterface $error) {
            return $this->json(['ok' => false, 'error' => $error->getMessage()], $error->getStatusCode());
        } catch (Throwable $error) {
            Log::error('[crm-sales] '.$error->getMessage(), ['exception' => $error]);

            return $this->json([
                'ok' => false,
                'error' => config('app.debug') ? $error->getMessage() : 'Erreur API pilotage commercial',
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
