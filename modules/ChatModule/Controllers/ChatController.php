<?php

declare(strict_types=1);

namespace Modules\ChatModule\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\ChatModule\Contracts\RAGPipelineServiceInterface;
use Modules\ChatModule\Exceptions\ChatSessionException;
use Modules\ChatModule\Requests\ChatRequest;
use Modules\ChatModule\Services\Pipeline\SessionManager;
use Modules\DocumentModule\Models\Document;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Chat Controller
 *
 * Handles HTTP endpoints for the chat system: asking questions (sync/stream),
 * listing sessions, viewing a single session, and deleting sessions.
 * All actions delegate to the RAGPipelineServiceInterface implementation.
 * Responses use the application's standard JSON envelope { success, data, message, errors }.
 */
class ChatController extends Controller
{
    private const GENERATION_FAILED = 'Unable to generate an answer right now. Please try again.';

    /**
     * Create a new ChatController instance
     *
     * @param  RAGPipelineServiceInterface  $pipeline  The RAG pipeline service. Example: $app->make(RAGPipelineServiceInterface::class)
     * @param  SessionManager  $sessions  Chat session store. Example: $app->make(SessionManager::class)
     */
    public function __construct(
        private RAGPipelineServiceInterface $pipeline,
        private SessionManager $sessions,
    ) {}

    /**
     * Ask a question (sync or streaming)
     *
     * Accepts a validated chat request and routes to either streaming SSE
     * response or synchronous JSON response based on the stream flag.
     * Maps InvalidArgumentException → 400, ChatSessionException → 422, others → 502.
     *
     * @param  ChatRequest  $request  The validated chat request containing question, session_id, stream flag, etc.
     *                                Example: new ChatRequest(['question' => 'What is Q3 revenue?', 'stream' => false])
     * @return JsonResponse|StreamedResponse JSON for sync, SSE stream for streaming
     *                                       Example (sync): {"success": true, "data": {"session_id": "01J...", "message": {...}}}
     *                                       Example (stream): StreamedResponse with Content-Type: text/event-stream
     *
     * @throws \InvalidArgumentException Via pipeline->ask() for empty/oversized questions (caught → 400)
     * @throws ChatSessionException Via pipeline->ask() for session/message limit errors (caught → 422)
     * @throws \Throwable Any other failure (e.g. LLM provider down) is logged and returned as a generic 502
     */
    public function ask(ChatRequest $request): JsonResponse|StreamedResponse
    {
        if ($request->boolean('stream', true)) {
            return $this->streamResponse($request);
        }

        try {
            $result = $this->pipeline->ask($request->input('question'), $this->pipelineOptions($request));

            return ApiResponse::success($result);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 400);
        } catch (ChatSessionException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logFailure($e, $request);

            return ApiResponse::error(self::GENERATION_FAILED, 502);
        }
    }

    /**
     * List chat sessions
     *
     * Returns a paginated list of chat sessions (20 per page) ordered by
     * last activity descending. Optionally scoped to the authenticated user.
     *
     * @param  Request  $request  The incoming request with optional page parameter and authenticated_user.
     *                            Example: new Request(['page' => 2])
     * @return JsonResponse Paginated session list with meta.
     *                      Example: {"success": true, "data": [...], "meta": {"current_page": 1, "last_page": 3, "total": 50}}
     */
    public function sessions(Request $request): JsonResponse
    {
        $page = $request->integer('page') ?: 1;

        return ApiResponse::paginated(
            $this->sessions->listSessions($request->input('authenticated_user')->id, $page),
        );
    }

    /**
     * Show a single session with messages
     *
     * Retrieves a chat session by ULID including all messages.
     * Returns 404 if the session is not found or does not belong to the user.
     *
     * @param  Request  $request  The incoming request with authenticated_user.
     *                            Example: new Request()
     * @param  string  $id  The session ULID. Example: "01J..."
     * @return JsonResponse Session data with messages.
     *                      Example: {"success": true, "data": {"id": "01J...", "title": "Chat", "messages": [...]}}
     */
    public function showSession(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(
            $this->sessions->getSession($id, $request->input('authenticated_user')->id),
        );
    }

    /**
     * Delete a session
     *
     * Soft-deletes the session and all its messages. Returns 404 if the
     * session is not found or does not belong to the user.
     *
     * @param  Request  $request  The incoming request with authenticated_user.
     *                            Example: new Request()
     * @param  string  $id  The session ULID to delete. Example: "01J..."
     * @return JsonResponse Success confirmation.
     *                      Example: {"success": true, "message": "Session deleted successfully."}
     */
    public function destroySession(Request $request, string $id): JsonResponse
    {
        $this->sessions->deleteSession($id, $request->input('authenticated_user')->id);

        return ApiResponse::success(message: 'Session deleted successfully.');
    }

    /**
     * Whitelist and tenant-scope a client-supplied document filter.
     *
     * Removes any unknown keys (e.g. user_ids), and — when tenant isolation
     * is enabled — restricts document_ids to documents owned by the requester.
     * The pipeline applies the same scoping again as a backstop.
     *
     * @param  array  $filter  Raw document_filter from the request.
     * @param  User|null  $user  The authenticated user (nullable).
     * @return array Scoped, whitelisted filter array.
     */
    private function scopeDocumentFilter(array $filter, ?User $user): array
    {
        $allowed = ['document_ids', 'date_from', 'date_to', 'meta'];
        $scoped = array_intersect_key($filter, array_flip($allowed));

        if (! config('rag.security.tenant_isolation', true) || $user === null) {
            return $scoped;
        }

        $documentIds = $scoped['document_ids'] ?? null;
        if ($documentIds !== null) {
            $scoped['document_ids'] = Document::whereIn('id', (array) $documentIds)
                ->where('user_id', $user->id)
                ->pluck('id')
                ->all();
        }

        return $scoped;
    }

    /**
     * Generate a streaming SSE response
     *
     * Sets up Server-Sent Event headers and streams pipeline events (status updates,
     * content chunks, sources, and done signal) as JSON-encoded data events.
     * Catches all exceptions and sends an error event before closing.
     *
     * @param  ChatRequest  $request  The validated chat request.
     *                                Example: new ChatRequest(['question' => 'What is Q3?', 'stream' => true])
     * @return StreamedResponse SSE stream response with Content-Type: text/event-stream
     *                          Example: StreamedResponse that yields "data: {"type":"chunk","content":"Revenue..."}\n\n"
     */
    private function streamResponse(ChatRequest $request): StreamedResponse
    {
        $options = $this->pipelineOptions($request);

        return response()->stream(function () use ($request, $options): void {
            try {
                foreach ($this->pipeline->askStream($request->input('question'), $options) as $event) {
                    $this->sendEvent($event);
                }
            } catch (\InvalidArgumentException|ChatSessionException $e) {
                $this->sendEvent(json_encode(['type' => 'error', 'message' => $e->getMessage()]));
            } catch (\Throwable $e) {
                $this->logFailure($e, $request);
                $this->sendEvent(json_encode(['type' => 'error', 'message' => self::GENERATION_FAILED]));
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Pipeline options shared by the sync and streaming paths.
     *
     * @param  ChatRequest  $request  The validated chat request
     * @return array Options for RAGPipelineService::ask()/askStream(). Example: ["session_id" => "01J...", "user_id" => "01J..."]
     */
    private function pipelineOptions(ChatRequest $request): array
    {
        $user = $request->input('authenticated_user');

        return [
            'session_id' => $request->input('session_id'),
            'document_filter' => $this->scopeDocumentFilter($request->input('document_filter', []), $user),
            'user_id' => $user->id,
            'llm_model_id' => $request->input('llm_model_id'),
            'think' => $request->input('think'),
        ];
    }

    /**
     * Write one SSE `data:` frame and flush it to the client.
     *
     * @param  string  $event  JSON-encoded event. Example: '{"type":"chunk","content":"Hi"}'
     */
    private function sendEvent(string $event): void
    {
        echo "data: {$event}\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    /**
     * Log an unexpected pipeline failure; the client only gets a generic message
     * so provider errors and internals are not exposed.
     *
     * @param  \Throwable  $e  The failure
     * @param  ChatRequest  $request  The chat request
     */
    private function logFailure(\Throwable $e, ChatRequest $request): void
    {
        Log::channel(config('rag.logging.channel', 'rag'))->error('Chat request failed', [
            'user_id' => $request->input('authenticated_user')?->id,
            'session_id' => $request->input('session_id'),
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
