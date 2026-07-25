<?php

namespace Modules\CrmDepositRequests\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\CrmCore\Http\Requests\CrmApiRequest;
use Modules\CrmDepositRequests\Services\DepositRequestService;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class DepositRequestApiController extends Controller
{
    public function __invoke(CrmApiRequest $request, DepositRequestService $depositRequests): JsonResponse
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

            $actor = $depositRequests->actorForUser($user);
            $body = $request->body();
            $siteId = $request->siteId($body);

            if ($siteId && ! array_key_exists('siteId', $body) && ! array_key_exists('site_id', $body)) {
                $body['siteId'] = $siteId;
            }

            return match ($action) {
                'bootstrap' => $this->json($depositRequests->bootstrap($actor, $siteId, [
                    'limit' => $request->query('limit', $body['limit'] ?? 20),
                    'status' => $request->query('status', $body['status'] ?? null),
                    'query' => $request->query('query', $body['query'] ?? ''),
                ])),
                'save_request' => $this->json($depositRequests->saveRequest($actor, $body)),
                'validate_request' => $this->json($depositRequests->validateRequest($actor, $body)),
                'delete_request' => $this->json($depositRequests->deleteRequest($actor, $body)),
                default => $this->json(['ok' => false, 'error' => 'Action inconnue'], 404),
            };
        } catch (HttpExceptionInterface $error) {
            return $this->json(['ok' => false, 'error' => $error->getMessage()], $error->getStatusCode());
        } catch (Throwable $error) {
            Log::error('[crm-deposit-requests] '.$error->getMessage(), ['exception' => $error]);

            return $this->json([
                'ok' => false,
                'error' => config('app.debug') ? $error->getMessage() : "Erreur API demande d'acompte",
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
