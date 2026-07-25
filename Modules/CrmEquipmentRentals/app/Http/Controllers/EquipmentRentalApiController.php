<?php

namespace Modules\CrmEquipmentRentals\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\CrmCore\Http\Requests\CrmApiRequest;
use Modules\CrmEquipmentRentals\Services\EquipmentRentalService;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class EquipmentRentalApiController extends Controller
{
    public function __invoke(CrmApiRequest $request, EquipmentRentalService $rentals): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->json(['ok' => true]);
        }

        try {
            $routeAction = $request->route('crm_action');
            $action = is_string($routeAction)
                ? $routeAction
                : $request->action($request->isMethod('GET') ? 'rentals' : 'bootstrap');

            if ($action === 'health') {
                return $this->json(['ok' => true, 'mode' => 'mysql']);
            }

            $user = $this->authenticatedApiUser($request);

            if (! $user) {
                return $this->json(['ok' => false, 'error' => 'Utilisateur HUB requis'], 401);
            }

            $actor = $rentals->actorForUser($user);
            $body = $request->body();
            $filters = $this->filters($request, $body);

            return match ($action) {
                'bootstrap_light' => $this->json($rentals->bootstrap($actor, includeRentals: false)),
                'bootstrap' => $this->json($rentals->bootstrap($actor, filters: $filters)),
                'rentals' => $this->json($rentals->rentals($actor, $filters)),
                'users' => $this->json($rentals->users($actor, $filters)),
                'equipment_items' => $this->json($rentals->equipmentItems($actor, $filters)),
                'equipment_categories' => $this->json($rentals->equipmentCategories($actor)),
                'create_rental' => $this->json($rentals->createRental($user, $actor, $body)),
                'update_rental' => $this->json($rentals->updateRental($user, $actor, $body)),
                'delete_rental' => $this->json($rentals->deleteRental($user, $actor, $body)),
                'save_equipment_item' => $this->json($rentals->saveEquipmentItem($user, $actor, $body)),
                'delete_equipment_item' => $this->json($rentals->deleteEquipmentItem($user, $actor, $body)),
                default => $this->json(['ok' => false, 'error' => 'Action inconnue'], 404),
            };
        } catch (AuthorizationException $error) {
            return $this->json(['ok' => false, 'error' => $error->getMessage()], 403);
        } catch (HttpExceptionInterface $error) {
            return $this->json(['ok' => false, 'error' => $error->getMessage()], $error->getStatusCode());
        } catch (ValidationException $error) {
            return $this->json(['ok' => false, 'error' => $error->validator->errors()->first()], 400);
        } catch (Throwable $error) {
            Log::error('[crm-equipment-rentals] '.$error->getMessage(), ['exception' => $error]);

            return $this->json([
                'ok' => false,
                'error' => config('app.debug') ? $error->getMessage() : 'Erreur API location materiel',
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
