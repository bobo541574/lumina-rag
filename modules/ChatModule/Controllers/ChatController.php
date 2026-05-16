<?php

declare(strict_types=1);

namespace Modules\ChatModule\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ChatModule\Contracts\RAGPipelineServiceInterface;
use Modules\ChatModule\Requests\ChatRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    private RAGPipelineServiceInterface $pipeline;

    public function __construct(RAGPipelineServiceInterface $pipeline)
    {
        $this->pipeline = $pipeline;
    }

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

    public function sessions(Request $request): JsonResponse
    {
        $user = $request->input('authenticated_user');
        $sessions = $this->pipeline->listSessions($user?->id);

        return response()->json([
            'success' => true,
            'data' => $sessions,
        ]);
    }

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
