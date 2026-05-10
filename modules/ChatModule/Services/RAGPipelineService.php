<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services;

use Generator;
use Modules\ChatModule\Contracts\RAGPipelineServiceInterface;
use Modules\ChatModule\Models\ChatMessage;
use Modules\ChatModule\Models\ChatSession;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\LLMModule\Contracts\LLMServiceInterface;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

class RAGPipelineService implements RAGPipelineServiceInterface
{
    private EmbeddingServiceInterface $embedder;

    private VectorStoreInterface $vectorStore;

    private LLMServiceInterface $llm;

    private int $topK;

    private float $similarityThreshold;

    private int $maxQuestionLength;

    private int $maxMessagesPerSession;

    public function __construct(
        EmbeddingServiceInterface $embedder,
        VectorStoreInterface $vectorStore,
        LLMServiceInterface $llm,
        int $topK = 5,
        float $similarityThreshold = 0.65,
        int $maxQuestionLength = 1000,
        int $maxMessagesPerSession = 100,
    ) {
        $this->embedder = $embedder;
        $this->vectorStore = $vectorStore;
        $this->llm = $llm;
        $this->topK = $topK;
        $this->similarityThreshold = $similarityThreshold;
        $this->maxQuestionLength = $maxQuestionLength;
        $this->maxMessagesPerSession = $maxMessagesPerSession;
    }

    public function ask(string $question, array $options = []): array
    {
        $question = $this->normalizeQuestion($question);
        $session = $this->resolveSession($options['session_id'] ?? null);
        $this->checkMessageLimit($session);
        $this->saveUserMessage($session, $question);
        $questionVector = $this->embedder->embed($question);
        $filters = $options['document_filter'] ?? [];
        $filters['similarity_threshold'] = $this->similarityThreshold;
        $chunks = $this->vectorStore->search($questionVector, $this->topK, $filters);

        if ($chunks === []) {
            $answer = $this->buildRefusalResponse($session);

            return $answer;
        }

        $confidence = $this->assessConfidence($chunks);
        $systemPrompt = $this->buildSystemPrompt($confidence);
        $context = array_slice($chunks, 0, $this->topK);
        $response = $this->llm->complete($systemPrompt, $question, $context, [
            'temperature' => 0.3,
        ]);
        $sources = $this->buildSources($chunks);
        $message = $this->saveAssistantMessage($session, $response->getContent(), $sources);

        return [
            'session_id' => $session->id,
            'message' => [
                'id' => $message->id,
                'role' => 'assistant',
                'content' => $response->getContent(),
                'sources' => $sources,
                'tokens_used' => $response->getTotalTokens(),
                'created_at' => $message->created_at->toIso8601String(),
            ],
        ];
    }

    public function askStream(string $question, array $options = []): Generator
    {
        $question = $this->normalizeQuestion($question);
        $session = $this->resolveSession($options['session_id'] ?? null);
        $this->saveUserMessage($session, $question);
        $questionVector = $this->embedder->embed($question);
        $filters = $options['document_filter'] ?? [];
        $filters['similarity_threshold'] = $this->similarityThreshold;
        $chunks = $this->vectorStore->search($questionVector, $this->topK, $filters);

        if ($chunks === []) {
            $refusal = $this->buildRefusalResponse($session);
            yield json_encode(['type' => 'answer', 'content' => $refusal['message']['content']]);
            yield json_encode(['type' => 'sources', 'sources' => []]);

            return;
        }

        $systemPrompt = $this->buildSystemPrompt($this->assessConfidence($chunks));
        $sources = $this->buildSources($chunks);
        yield json_encode(['type' => 'sources', 'sources' => $sources]);

        $fullContent = '';
        $stream = $this->llm->completeStream($systemPrompt, $question, $chunks, ['temperature' => 0.3]);

        foreach ($stream as $chunk) {
            $fullContent .= $chunk;
            yield json_encode(['type' => 'chunk', 'content' => $chunk]);
        }

        $this->saveAssistantMessage($session, $fullContent, $sources);
        yield json_encode(['type' => 'done', 'session_id' => $session->id]);
    }

    public function listSessions(): array
    {
        return ChatSession::orderByDesc('last_activity_at')
            ->paginate(20)
            ->toArray();
    }

    public function getSession(string $id): array
    {
        $session = ChatSession::with('messages')->findOrFail($id);

        return $session->toArray();
    }

    public function deleteSession(string $id): void
    {
        $session = ChatSession::findOrFail($id);
        $session->messages()->delete();
        $session->delete();
    }

    private function normalizeQuestion(string $question): string
    {
        $question = trim($question);
        if ($question === '') {
            throw new \InvalidArgumentException('Question cannot be empty.');
        }
        if (mb_strlen($question) > $this->maxQuestionLength) {
            $question = mb_substr($question, 0, $this->maxQuestionLength);
        }

        return $question;
    }

    private function resolveSession(?string $sessionId): ChatSession
    {
        if ($sessionId !== null) {
            $session = ChatSession::find($sessionId);
            if ($session === null) {
                throw new \RuntimeException('Chat session not found.');
            }
            if ($session->trashed()) {
                throw new \RuntimeException('Chat session has been deleted.');
            }
            $session->update(['last_activity_at' => now()]);

            return $session;
        }

        return ChatSession::create([
            'title' => 'New Chat',
            'last_activity_at' => now(),
        ]);
    }

    private function checkMessageLimit(ChatSession $session): void
    {
        $count = $session->messages()->count();
        if ($count >= $this->maxMessagesPerSession) {
            throw new \RuntimeException('Session message limit reached. Please start a new chat.');
        }
    }

    private function saveUserMessage(ChatSession $session, string $question): ChatMessage
    {
        if ($session->title === 'New Chat') {
            $session->update([
                'title' => mb_substr($question, 0, 50),
            ]);
        }
        $session->update(['last_activity_at' => now()]);
        $session->increment('message_count');

        return ChatMessage::create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => $question,
        ]);
    }

    private function saveAssistantMessage(ChatSession $session, string $content, array $sources): ChatMessage
    {
        $session->update(['last_activity_at' => now()]);
        $session->increment('message_count');

        return ChatMessage::create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => $content,
            'sources' => $sources,
        ]);
    }

    private function buildRefusalResponse(ChatSession $session): array
    {
        $content = 'I cannot answer this question based on the available documents.';
        $message = $this->saveAssistantMessage($session, $content, []);

        return [
            'session_id' => $session->id,
            'message' => [
                'id' => $message->id,
                'role' => 'assistant',
                'content' => $content,
                'sources' => [],
                'created_at' => $message->created_at->toIso8601String(),
            ],
        ];
    }

    private function assessConfidence(array $chunks): string
    {
        $aboveThreshold = count($chunks);

        return match (true) {
            $aboveThreshold >= 3 => 'high',
            $aboveThreshold >= 1 => 'low',
            default => 'none',
        };
    }

    private function buildSystemPrompt(string $confidence): string
    {
        $prompt = 'You are a helpful AI assistant. Answer the user\'s question based ONLY on the provided context.

Rules:
1. If the context contains the answer, provide it clearly and concisely.
2. Do not use any knowledge outside the provided context.
3. Cite sources when possible by referencing the document title.
4. Answer in the same language as the question.';

        if ($confidence === 'low') {
            $prompt .= "\n5. Note: The available information may be limited. If you are uncertain, acknowledge this.";
        }

        return $prompt;
    }

    private function buildSources(array $chunks): array
    {
        return array_map(fn (object $chunk): array => [
            'document_id' => $chunk->document_id,
            'document_title' => $chunk->document_title,
            'chunk_index' => $chunk->chunk_index,
            'page_number' => $chunk->page_number ?? null,
            'similarity_score' => round((float) $chunk->similarity_score, 4),
            'excerpt' => mb_substr((string) $chunk->content, 0, 200),
        ], $chunks);
    }
}
