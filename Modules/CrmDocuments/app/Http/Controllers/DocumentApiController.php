<?php

namespace Modules\CrmDocuments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CrmDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\CrmCore\Http\Requests\CrmApiRequest;
use Modules\CrmDocuments\Services\DocumentLibraryService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class DocumentApiController extends Controller
{
    public function __invoke(CrmApiRequest $request, DocumentLibraryService $documents): JsonResponse
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

            $actor = $documents->actorForUser($user);
            $body = $request->body();
            $category = (string) ($request->query('category') ?? $body['category'] ?? '');

            return match ($action) {
                'bootstrap' => $this->json($documents->bootstrap($actor, $category, $request->siteId($body))),
                'save_directory' => $this->json($documents->saveDirectory($actor, $body)),
                'delete_directory' => $this->json($documents->deleteDirectory($actor, $body)),
                'save_document' => $this->json($documents->saveDocument($actor, $body)),
                'delete_document' => $this->json($documents->deleteDocument($actor, $body)),
                default => $this->json(['ok' => false, 'error' => 'Action inconnue'], 404),
            };
        } catch (HttpExceptionInterface $error) {
            return $this->json(['ok' => false, 'error' => $error->getMessage()], $error->getStatusCode());
        } catch (ValidationException $error) {
            return $this->json(['ok' => false, 'error' => $error->validator->errors()->first()], 400);
        } catch (Throwable $error) {
            Log::error('[crm-documents] '.$error->getMessage(), ['exception' => $error]);

            return $this->json([
                'ok' => false,
                'error' => config('app.debug') ? $error->getMessage() : 'Erreur API documents',
            ], 500);
        }
    }

    public function download(Request $request, CrmDocument $document, DocumentLibraryService $documents): BinaryFileResponse
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $actor = $documents->actorForUser($user);
        $document = $documents->documentForDownload($actor, (int) $document->id);

        if (! Storage::disk($document->disk)->exists($document->disk_path)) {
            abort(404);
        }

        return response()->file(
            Storage::disk($document->disk)->path($document->disk_path),
            [
                'Content-Type' => $document->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.addslashes($document->downloadName()).'"',
            ],
        );
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()
            ->json($data, $status, [], JSON_UNESCAPED_UNICODE)
            ->withHeaders($this->crmApiHeaders());
    }
}
