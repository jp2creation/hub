<?php

namespace Modules\CrmSalesTours\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\CrmCore\Http\Requests\CrmApiRequest;
use Modules\CrmSalesTours\Services\SalesTourService;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class SalesTourApiController extends Controller
{
    public function __invoke(CrmApiRequest $request, SalesTourService $salesTours): JsonResponse
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

            $actor = $salesTours->actorForUser($user);
            $body = $request->body();
            $siteId = $request->siteId($body);

            if ($siteId && ! array_key_exists('siteId', $body) && ! array_key_exists('site_id', $body)) {
                $body['siteId'] = $siteId;
            }

            return match ($action) {
                'bootstrap' => $this->json($salesTours->bootstrap($actor, $siteId, [
                    'month' => $request->query('month', $body['month'] ?? null),
                    'tourId' => $request->query('tourId', $body['tourId'] ?? $body['tour_id'] ?? null),
                    'representativeId' => $request->query('representativeId', $body['representativeId'] ?? $body['representative_user_id'] ?? null),
                    'status' => $request->query('status', $body['status'] ?? null),
                    'query' => $request->query('query', $body['query'] ?? ''),
                ])),
                'save_tour' => $this->json($salesTours->saveTour($actor, $body)),
                'delete_tour' => $this->json($salesTours->deleteTour($actor, $body)),
                'save_visit' => $this->json($salesTours->saveVisit($actor, $body)),
                'delete_visit' => $this->json($salesTours->deleteVisit($actor, $body)),
                'save_report' => $this->json($salesTours->saveReport($actor, $body)),
                default => $this->json(['ok' => false, 'error' => 'Action inconnue'], 404),
            };
        } catch (HttpExceptionInterface $error) {
            return $this->json(['ok' => false, 'error' => $error->getMessage()], $error->getStatusCode());
        } catch (Throwable $error) {
            Log::error('[crm-sales-tours] '.$error->getMessage(), ['exception' => $error]);

            return $this->json([
                'ok' => false,
                'error' => config('app.debug') ? $error->getMessage() : 'Erreur API rapport de visite',
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
