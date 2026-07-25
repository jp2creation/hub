<?php

namespace Modules\CrmReservations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\CrmCore\Http\Requests\CrmApiRequest;
use Modules\CrmReservations\Services\ReservationService;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ReservationApiController extends Controller
{
    public function __invoke(CrmApiRequest $request, ReservationService $reservations): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->json(['ok' => true]);
        }

        try {
            $routeAction = $request->route('crm_action');
            $action = is_string($routeAction)
                ? $routeAction
                : $request->action($request->isMethod('GET') ? 'reservations' : 'bootstrap');

            if ($action === 'health') {
                return $this->json(['ok' => true, 'mode' => 'mysql']);
            }

            $user = $this->authenticatedApiUser($request);

            if (! $user) {
                return $this->json(['ok' => false, 'error' => 'Utilisateur HUB requis'], 401);
            }

            $actor = $reservations->actorForUser($user);
            $body = $request->body();
            $filters = $this->filters($request, $body);

            return match ($action) {
                'bootstrap_light' => $this->json($reservations->bootstrap($actor, includeReservations: false)),
                'bootstrap' => $this->json($reservations->bootstrap($actor, filters: $filters)),
                'reservations' => $this->json($reservations->reservations($actor, $filters)),
                'users' => $this->json($reservations->users($actor, $filters)),
                'vehicles' => $this->json($reservations->vehicles($actor, $filters)),
                'create_reservation' => $this->json($reservations->createReservation($user, $actor, $body)),
                'update_reservation' => $this->json($reservations->updateReservation($user, $actor, $body)),
                'save_vehicle' => $this->json($reservations->saveVehicle($user, $actor, $body)),
                'delete_vehicle' => $this->json($reservations->deleteVehicle($user, $actor, $body)),
                'delete_reservation' => $this->json($reservations->deleteReservation($user, $actor, $body)),
                default => $this->json(['ok' => false, 'error' => 'Action inconnue'], 404),
            };
        } catch (AuthorizationException $error) {
            return $this->json(['ok' => false, 'error' => $error->getMessage()], 403);
        } catch (HttpExceptionInterface $error) {
            return $this->json(['ok' => false, 'error' => $error->getMessage()], $error->getStatusCode());
        } catch (ValidationException $error) {
            return $this->json(['ok' => false, 'error' => $error->validator->errors()->first()], 400);
        } catch (Throwable $error) {
            Log::error('[crm-reservations] '.$error->getMessage(), ['exception' => $error]);

            return $this->json([
                'ok' => false,
                'error' => config('app.debug') ? $error->getMessage() : 'Erreur API reservations',
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
     * @return array<string, mixed>
     */
    private function filters(CrmApiRequest $request, array $body): array
    {
        return [...$request->query->all(), ...$body];
    }
}
