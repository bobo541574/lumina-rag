<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\DocumentModule\Contracts\DocumentServiceInterface;
use Modules\DocumentModule\Models\Document;
use Modules\DocumentModule\Requests\UploadDocumentRequest;

class DocumentController extends Controller
{
    private DocumentServiceInterface $documentService;

    public function __construct(DocumentServiceInterface $documentService)
    {
        $this->documentService = $documentService;
    }

    public function upload(UploadDocumentRequest $request): JsonResponse
    {
        try {
            $user = $request->input('authenticated_user');
            $document = $this->documentService->upload(
                $request->file('file'),
                $request->input('title'),
                $user?->id,
                $request->input('embedding_model'),
                $request->input('embedding_model_id'),
                $request->input('report_date'),
                $request->input('project'),
            );

            return response()->json([
                'success' => true,
                'message' => 'Document uploaded successfully and queued for processing.',
                'data' => $document->toArray(),
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->input('authenticated_user');
        $documents = $this->documentService->listDocuments(
            $request->only(['status', 'per_page', 'page', 'search', 'sort_key', 'sort_dir']),
            $user?->id,
        );

        return response()->json([
            'success' => true,
            'data' => $documents,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->input('authenticated_user');
            $query = Document::withCount('chunks')->where('id', $id);

            if ($user !== null) {
                $query->where('user_id', $user->id);
            }

            $document = $query->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => $document->toArray(),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found.',
            ], 404);
        }
    }

    public function status(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->input('authenticated_user');
            $query = Document::where('id', $id);

            if ($user !== null) {
                $query->where('user_id', $user->id);
            }

            $document = $query->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $document->id,
                    'status' => $document->status,
                    'chunks_count' => $document->chunks_count,
                    'error_message' => $document->error_message,
                    'processed_at' => $document->processed_at,
                ],
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found.',
            ], 404);
        }
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->input('authenticated_user');
            $document = $this->documentService->retryDocument($id, $user?->id);

            return response()->json([
                'success' => true,
                'message' => 'Document retry initiated.',
                'data' => $document->toArray(),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found.',
            ], 404);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->input('authenticated_user');
            $document = $this->documentService->updateDocument(
                $id,
                $request->only(['title', 'description']),
                $user?->id,
            );

            return response()->json([
                'success' => true,
                'message' => 'Document updated successfully.',
                'data' => $document->toArray(),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found.',
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->input('authenticated_user');
            $this->documentService->deleteDocument($id, $user?->id);

            return response()->json([
                'success' => true,
                'message' => 'Document deleted successfully.',
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found.',
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
