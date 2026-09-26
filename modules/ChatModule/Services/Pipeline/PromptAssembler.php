<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services\Pipeline;

use Modules\ChatModule\Models\ChatMessage;
use Modules\ChatModule\Models\ChatSession;

/**
 * Turns retrieved chunks into the LLM prompt.
 *
 * Picks the system prompt from retrieval confidence, orders context for
 * "lost in the middle", prepends a Search Scope note describing the applied
 * filters, and prefixes recent conversation history for follow-ups.
 */
class PromptAssembler
{
    public function __construct(
        private ChunkProcessor $chunkProcessor,
        private ResponseBuilder $responseBuilder,
        private int $historyWindow = 4,
    ) {}

    /**
     * @param  array<object>  $chunks  Retrieved chunks, best first
     * @param  array  $filters  Filters used for retrieval. Example: ["report_date_from" => "2026-05-15"]
     * @param  string  $question  Question with time references resolved. Example: "What happened on 2026-05-15?"
     * @param  ChatSession  $session  Session for history lookup
     * @return array{systemPrompt: string, userPrompt: string, context: array<object>}
     */
    public function assemble(array $chunks, array $filters, string $question, ChatSession $session): array
    {
        $systemPrompt = $this->responseBuilder->buildSystemPrompt(
            $this->chunkProcessor->assessConfidence($chunks),
            $this->chunkProcessor->hasOldDocuments($chunks),
        );
        $context = $this->chunkProcessor->reorderForLostInTheMiddle($chunks);

        // Tell the LLM which filters were applied (e.g. date=2026-05-15), so it
        // does not substitute its own guess for relative dates like "yesterday".
        $scope = $this->responseBuilder->buildFilterNote($filters);
        if ($scope !== '') {
            array_unshift($context, (object) [
                'document_title' => 'Search Scope',
                'content' => $scope,
                'page_number' => null,
            ]);
        }

        return [
            'systemPrompt' => $systemPrompt,
            'userPrompt' => $this->withHistory($question, $session),
            'context' => $context,
        ];
    }

    /**
     * Prefix the question with recent turns so follow-ups can be resolved.
     *
     * @param  string  $question  Current question
     * @param  ChatSession  $session  Session whose latest message is the current question
     * @return string Question, possibly prefixed with "Previous conversation:"
     */
    private function withHistory(string $question, ChatSession $session): string
    {
        if ($session->message_count <= 1) {
            return $question;
        }

        $history = ChatMessage::where('session_id', $session->id)
            ->orderBy('created_at', 'desc')
            ->skip(1)
            ->take($this->historyWindow)
            ->get(['role', 'content'])
            ->reverse();

        $conversation = '';
        foreach ($history as $message) {
            $role = $message->role === 'user' ? 'User' : 'Assistant';
            $conversation .= "{$role}: {$message->content}\n\n";
        }

        return "Previous conversation:\n{$conversation}Current question: {$question}";
    }
}
