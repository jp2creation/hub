<?php

namespace Modules\CrmCheckRemittances\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\CrmCheckRemittances\Services\CheckRemittanceService;
use Modules\CrmCore\Http\Requests\CrmApiRequest;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class CheckRemittanceApiController extends Controller
{
    public function __invoke(CrmApiRequest $request, CheckRemittanceService $remittances): JsonResponse
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

            $actor = $remittances->actorForUser($user);
            $body = $request->body();
            $siteId = $request->siteId($body);

            if ($siteId && ! array_key_exists('siteId', $body) && ! array_key_exists('site_id', $body)) {
                $body['siteId'] = $siteId;
            }

            return match ($action) {
                'bootstrap' => $this->json($remittances->bootstrap($actor, $siteId, [
                    'limit' => $request->query('limit', $body['limit'] ?? 10),
                    'year' => $request->query('year', $body['year'] ?? null),
                    'month' => $request->query('month', $body['month'] ?? null),
                ])),
                'show_remittance' => $this->json($remittances->showRemittance($actor, $body)),
                'save_remittance' => $this->json($remittances->saveRemittance($actor, $body)),
                'delete_remittance' => $this->json($remittances->deleteRemittance($actor, $body)),
                'detect_check_ocr' => $this->json($remittances->detectCheckOcr($actor, $body)),
                'save_check' => $this->json($remittances->saveCheck($actor, $body)),
                'delete_check' => $this->json($remittances->deleteCheck($actor, $body)),
                default => $this->json(['ok' => false, 'error' => 'Action inconnue'], 404),
            };
        } catch (HttpExceptionInterface $error) {
            return $this->json(['ok' => false, 'error' => $error->getMessage()], $error->getStatusCode());
        } catch (Throwable $error) {
            Log::error('[crm-check-remittances] '.$error->getMessage(), ['exception' => $error]);

            return $this->json([
                'ok' => false,
                'error' => config('app.debug') ? $error->getMessage() : 'Erreur API remise de cheques',
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
