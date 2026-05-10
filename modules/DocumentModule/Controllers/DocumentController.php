<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\DocumentModule\Models\Document;
use Modules\DocumentModule\Requests\UploadDocumentRequest;
use Modules\DocumentModule\Services\DocumentService;

class DocumentController extends Controller
{
    private DocumentService $documentService;

    public function __construct(DocumentService $documentService)
    {
        $this->documentService = $documentService;
    }

    public function upload(UploadDocumentRequest $request): JsonResponse
    {
        try {
            $document = $this->documentService->upload(
                $request->file('file'),
                $request->input('title'),
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
        $documents = $this->documentService->listDocuments(
            $request->only(['status', 'per_page']),
        );

        return response()->json([
            'success' => true,
            'data' => $documents,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $document = Document::withCount('chunks')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $document->toArray(),
        ]);
    }

    public function status(string $id): JsonResponse
    {
        $document = Document::findOrFail($id);

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
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->documentService->deleteDocument($id);

            return response()->json([
                'success' => true,
                'message' => 'Document deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
