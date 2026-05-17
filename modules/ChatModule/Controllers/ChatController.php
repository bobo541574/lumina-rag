<?php

declare(strict_types=1);

namespace Modules\ChatModule\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ChatModule\Contracts\RAGPipelineServiceInterface;
use Modules\ChatModule\Requests\ChatRequest;
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
    private RAGPipelineServiceInterface $pipeline;

    /**
     * Create a new ChatController instance
     *
     * @param  RAGPipelineServiceInterface  $pipeline  The RAG pipeline service. Example: $app->make(RAGPipelineServiceInterface::class)
     */
    public function __construct(RAGPipelineServiceInterface $pipeline)
    {
        $this->pipeline = $pipeline;
    }

    /**
     * Ask a question (sync or streaming)
     *
     * Accepts a validated chat request and routes to either streaming SSE
     * response or synchronous JSON response based on the stream flag.
     * Catches InvalidArgumentException (400) and RuntimeException (422).
     *
     * @param  ChatRequest  $request  The validated chat request containing question, session_id, stream flag, etc.
     *                                Example: new ChatRequest(['question' => 'What is Q3 revenue?', 'stream' => false])
     * @return JsonResponse|StreamedResponse JSON for sync, SSE stream for streaming
     *                                       Example (sync): {"success": true, "data": {"session_id": "01J...", "message": {...}}}
     *                                       Example (stream): StreamedResponse with Content-Type: text/event-stream
     *
     * @throws \InvalidArgumentException Via pipeline->ask() for empty/oversized questions (caught → 400)
     * @throws \RuntimeException Via pipeline->ask() for session/message limit errors (caught → 422)
     */
    public function ask(ChatRequest $request): JsonResponse|StreamedResponse
    {
        $stream = $request->boolean('stream', true);

        if ($stream) {
            return $this->streamResponse($request);
        }

        try {
            $user = $request->input('authenticated_user');
            $result = $this->pipeline->ask(
                $request->input('question'),
                [
                    'session_id' => $request->input('session_id'),
                    'document_filter' => $request->input('document_filter', []),
                    'user_id' => $user?->id,
                    'llm_model_id' => $request->input('llm_model_id'),
                ],
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
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
        $user = $request->input('authenticated_user');
        $page = $request->integer('page') ?: 1;
        $sessions = $this->pipeline->listSessions($user?->id, $page);

        return response()->json([
            'success' => true,
            'data' => $sessions['data'],
            'meta' => [
                'current_page' => $sessions['current_page'],
                'last_page' => $sessions['last_page'],
                'per_page' => $sessions['per_page'],
                'total' => $sessions['total'],
                'from' => $sessions['from'],
                'to' => $sessions['to'],
            ],
        ]);
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
        try {
            $user = $request->input('authenticated_user');
            $session = $this->pipeline->getSession($id, $user?->id);

            return response()->json([
                'success' => true,
                'data' => $session,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Session not found.',
            ], 404);
        }
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
        try {
            $user = $request->input('authenticated_user');
            $this->pipeline->deleteSession($id, $user?->id);

            return response()->json([
                'success' => true,
                'message' => 'Session deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Session not found.',
            ], 404);
        }
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
        $user = $request->input('authenticated_user');

        return response()->stream(function () use ($request, $user): void {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');

            try {
                $generator = $this->pipeline->askStream(
                    $request->input('question'),
                    [
                        'session_id' => $request->input('session_id'),
                        'document_filter' => $request->input('document_filter', []),
                        'user_id' => $user?->id,
                        'llm_model_id' => $request->input('llm_model_id'),
                    ],
                );

                foreach ($generator as $event) {
                    echo "data: {$event}\n\n";
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                }
            } catch (\Throwable $e) {
                echo 'data: '.json_encode(['type' => 'error', 'message' => $e->getMessage()])."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
