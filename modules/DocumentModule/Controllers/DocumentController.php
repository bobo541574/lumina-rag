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

/**
 * Document Controller
 *
 * Handles all HTTP endpoints for document CRUD operations: upload, list, show,
 * status polling, update metadata, delete, and retry failed documents. Delegates
 * business logic to DocumentServiceInterface. Validation is handled by
 * UploadDocumentRequest form request. All responses follow the standard
 * { success, message, data, errors } envelope.
 *
 * @param  DocumentServiceInterface  $documentService  The document service implementing business logic. Example: mock(DocumentServiceInterface::class)
 */
class DocumentController extends Controller
{
    private DocumentServiceInterface $documentService;

    public function __construct(DocumentServiceInterface $documentService)
    {
        $this->documentService = $documentService;
    }

    /**
     * Upload a new document
     *
     * Accepts a multipart file upload, validates via UploadDocumentRequest, delegates
     * to DocumentService for storage and queue dispatch. Returns 201 on success,
     * 409 for duplicates, 400 for validation errors.
     *
     * @param  UploadDocumentRequest  $request  The validated form request with file + optional metadata. Example: new UploadDocumentRequest()
     * @return JsonResponse 201 with document data, 409 on duplicate, 400 on validation error.
     *                      Example: { success: true, message: "...", data: { id: "01J...", status: "pending" } }
     *
     * @throws \RuntimeException Caught and returned as 409 for duplicates
     * @throws \InvalidArgumentException Caught and returned as 400 for validation failures
     */
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

    /**
     * List documents with filtering and pagination
     *
     * Accepts query parameters for status filter, free-text search, sorting, and
     * pagination. Delegates to DocumentService::listDocuments and returns a
     * paginated response with meta information.
     *
     * @param  Request  $request  The incoming request with optional query params: status,
     *                            search, sort_key, sort_dir, per_page, page. Example: request()
     * @return JsonResponse 200 with paginated document data array and meta block.
     *                      Example: { success: true, data: [...], meta: { current_page: 1, total: 42 } }
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->input('authenticated_user');
        $filters = $request->only(['status', 'per_page', 'page', 'search', 'sort_key', 'sort_dir']);
        $filters['per_page'] = min(
            (int) ($filters['per_page'] ?? config('rag.pagination.per_page')),
            (int) config('rag.pagination.max_per_page'),
        );
        $documents = $this->documentService->listDocuments(
            $filters,
            $user?->id,
        );

        return response()->json([
            'success' => true,
            'data' => $documents['data'],
            'meta' => [
                'current_page' => $documents['current_page'],
                'last_page' => $documents['last_page'],
                'per_page' => $documents['per_page'],
                'total' => $documents['total'],
                'from' => $documents['from'],
                'to' => $documents['to'],
            ],
        ]);
    }

    /**
     * Show a single document with chunk count
     *
     * Returns full document details including a chunks_count aggregate. Scoped to
     * the authenticated user if available.
     *
     * @param  Request  $request  The incoming request with authenticated_user. Example: request()
     * @param  string  $id  The document ULID. Example: "01J..."
     * @return JsonResponse 200 with document data, or 404 if not found.
     *                      Example: { success: true, data: { id: "01J...", chunks_count: 15, ... } }
     *
     * @throws ModelNotFoundException Caught and returned as 404
     */
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

    /**
     * Poll document processing status
     *
     * Lightweight endpoint returning only status-relevant fields (id, status,
     * chunks_count, error_message, processed_at). Used by the frontend for
     * polling the async processing pipeline.
     *
     * @param  Request  $request  The incoming request with authenticated_user. Example: request()
     * @param  string  $id  The document ULID. Example: "01J..."
     * @return JsonResponse 200 with status data, or 404 if not found.
     *                      Example: { success: true, data: { id: "01J...", status: "completed", chunks_count: 15 } }
     *
     * @throws ModelNotFoundException Caught and returned as 404
     */
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

    /**
     * Retry processing a failed document
     *
     * Resets a failed document to "pending" and re-dispatches the processing job.
     * Only documents with status "failed" are eligible.
     *
     * @param  Request  $request  The incoming request with authenticated_user. Example: request()
     * @param  string  $id  The document ULID. Example: "01J..."
     * @return JsonResponse 200 with updated document data, 400 if not retryable, 404 if not found.
     *                      Example: { success: true, message: "Document retry initiated.", data: { id: "01J...", status: "pending" } }
     *
     * @throws \RuntimeException Caught and returned as 400 (e.g. document not in "failed" status)
     */
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

    /**
     * Update document metadata
     *
     * Accepts updates to allowed fields: title, description, report_date, project.
     * Only the specified fields are updated; all others are ignored.
     *
     * @param  Request  $request  The incoming request with authenticated_user and optional fields. Example: request()
     * @param  string  $id  The document ULID. Example: "01J..."
     * @return JsonResponse 200 with updated document data, 404 if not found, 500 on error.
     *                      Example: { success: true, message: "Document updated successfully.", data: { ... } }
     *
     * @throws ModelNotFoundException Caught and returned as 404
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->input('authenticated_user');
            $document = $this->documentService->updateDocument(
                $id,
                $request->only(['title', 'description', 'report_date', 'project']),
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

    /**
     * Delete a document and all associated data
     *
     * Removes vectors, chunks, the physical file, and soft-deletes the document record.
     * Scoped to the authenticated user if available.
     *
     * @param  Request  $request  The incoming request with authenticated_user. Example: request()
     * @param  string  $id  The document ULID. Example: "01J..."
     * @return JsonResponse 200 on success, 404 if not found, 500 on error.
     *                      Example: { success: true, message: "Document deleted successfully." }
     *
     * @throws ModelNotFoundException Caught and returned as 404
     */
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
