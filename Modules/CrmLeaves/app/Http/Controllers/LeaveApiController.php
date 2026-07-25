<?php

namespace Modules\CrmLeaves\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\CrmCore\Http\Requests\CrmApiRequest;
use Modules\CrmLeaves\Exceptions\LeaveApiException;
use Modules\CrmLeaves\Services\LeaveService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class LeaveApiController extends Controller
{
    public function __invoke(CrmApiRequest $request, LeaveService $leaves): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->json(['ok' => true]);
        }

        try {
            $user = $this->authenticatedApiUser($request);

            if (! $user) {
                return $this->json(['ok' => false, 'error' => 'Utilisateur HUB requis'], 401);
            }

            $actor = $leaves->actorForUser($user);
            $action = $request->action();
            $body = $request->body();
            $siteId = $request->siteId($body);

            if ($siteId && ! array_key_exists('siteId', $body) && ! array_key_exists('site_id', $body)) {
                $body['siteId'] = $siteId;
            }

            return match ($action) {
                'bootstrap' => $this->json($leaves->bootstrap($actor, $siteId)),
                'export_options' => $this->json($leaves->exportOptions($actor, $body)),
                'export_pdf' => $this->pdf($leaves->exportPdf($actor, $body)),
                'save_leave' => $this->json($leaves->saveLeave($actor, $body)),
                'approve_leave' => $this->json($leaves->approveLeave($actor, $body)),
                'refuse_leave' => $this->json($leaves->refuseLeave($actor, $body)),
                'delete_leave' => $this->json($leaves->deleteLeave($actor, $body)),
                default => $this->json(['ok' => false, 'error' => 'Action inconnue'], 404),
            };
        } catch (LeaveApiException $error) {
            return $this->json([
                'ok' => false,
                'error' => $error->getMessage(),
                'code' => $error->errorCode,
            ], $error->getStatusCode());
        } catch (HttpExceptionInterface $error) {
            return $this->json(['ok' => false, 'error' => $error->getMessage()], $error->getStatusCode());
        } catch (Throwable $error) {
            Log::error('[crm-leaves] '.$error->getMessage(), ['exception' => $error]);

            return $this->json([
                'ok' => false,
                'error' => config('app.debug') ? $error->getMessage() : 'Erreur API conges',
            ], 500);
        }
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()
            ->json($data, $status, [], JSON_UNESCAPED_UNICODE)
            ->withHeaders($this->crmApiHeaders());
    }

    /**
     * @param  array{filename:string, contents:string}  $export
     */
    private function pdf(array $export): Response
    {
        return response($export['contents'], 200, [
            ...$this->crmApiHeaders(),
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
