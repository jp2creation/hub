<?php

namespace Modules\CrmCashControl\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\CrmCashControl\Services\CashControlService;
use Modules\CrmCore\Http\Requests\CrmApiRequest;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class CashControlApiController extends Controller
{
    public function __invoke(CrmApiRequest $request, CashControlService $cashControl): JsonResponse
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

            $actor = $cashControl->actorForUser($user);
            $body = $request->body();
            $siteId = $request->siteId($body);

            if ($siteId && ! array_key_exists('siteId', $body) && ! array_key_exists('site_id', $body)) {
                $body['siteId'] = $siteId;
            }

            return match ($action) {
                'bootstrap' => $this->json($cashControl->bootstrap($actor, $siteId)),
                'create_day' => $this->json($cashControl->createDay($actor, $body)),
                'save_day' => $this->json($cashControl->saveDay($actor, $body)),
                'save_receipt' => $this->json($cashControl->saveReceipt($actor, $body)),
                'delete_receipt' => $this->json($cashControl->deleteReceipt($actor, $body)),
                'save_cash_count' => $this->json($cashControl->saveCashCount($actor, $body)),
                'save_movement' => $this->json($cashControl->saveMovement($actor, $body)),
                'delete_movement' => $this->json($cashControl->deleteMovement($actor, $body)),
                'delete_day' => $this->json($cashControl->deleteDay($actor, $body)),
                default => $this->json(['ok' => false, 'error' => 'Action inconnue'], 404),
            };
        } catch (HttpExceptionInterface $error) {
            return $this->json(['ok' => false, 'error' => $error->getMessage()], $error->getStatusCode());
        } catch (Throwable $error) {
            Log::error('[crm-cash-control] '.$error->getMessage(), ['exception' => $error]);

            return $this->json([
                'ok' => false,
                'error' => config('app.debug') ? $error->getMessage() : 'Erreur API controle caisse',
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
