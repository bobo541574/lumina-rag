<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services;

use Carbon\Carbon;
use Generator;
use Illuminate\Support\Facades\Log;
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

    private string $searchMode;

    private bool $queryExpansionEnabled;

    private int $numExpansionQueries;

    private bool $mmrEnabled;

    private float $mmrLambda;

    private ?string $userId;

    public function __construct(
        EmbeddingServiceInterface $embedder,
        VectorStoreInterface $vectorStore,
        LLMServiceInterface $llm,
        int $topK = 5,
        float $similarityThreshold = 0.65,
        int $maxQuestionLength = 1000,
        int $maxMessagesPerSession = 100,
        string $searchMode = 'hybrid',
        bool $queryExpansionEnabled = false,
        int $numExpansionQueries = 3,
        bool $mmrEnabled = true,
        float $mmrLambda = 0.7,
        ?string $userId = null,
    ) {
        $this->embedder = $embedder;
        $this->vectorStore = $vectorStore;
        $this->llm = $llm;
        $this->topK = $topK;
        $this->similarityThreshold = $similarityThreshold;
        $this->maxQuestionLength = $maxQuestionLength;
        $this->maxMessagesPerSession = $maxMessagesPerSession;
        $this->searchMode = $searchMode;
        $this->queryExpansionEnabled = $queryExpansionEnabled;
        $this->numExpansionQueries = $numExpansionQueries;
        $this->mmrEnabled = $mmrEnabled;
        $this->mmrLambda = $mmrLambda;
        $this->userId = $userId;
    }

    public function ask(string $question, array $options = []): array
    {
        set_time_limit(120);
        $start = microtime(true);
        $question = $this->normalizeQuestion($question);
        $session = $this->resolveSession($options['session_id'] ?? null, $options['user_id'] ?? $this->userId);
        $this->checkMessageLimit($session);
        $this->saveUserMessage($session, $question);

        $searchQueries = $this->expandQuery($question);
        $autoFilters = $this->extractFiltersFromQuestion($question);

        $allChunks = [];
        $t0 = microtime(true);

        foreach ($searchQueries as $sq) {
            $questionVector = $this->embedder->embed($sq);
            $minThreshold = min($this->similarityThreshold, 0.40);
            $filters = array_merge($autoFilters, $options['document_filter'] ?? []);
            $filters['similarity_threshold'] = $minThreshold;
            $filters['model_name'] = config('rag.embedding.model', 'text-embedding-3-small');

            $chunks = $this->searchMode === 'hybrid'
                ? $this->vectorStore->searchHybrid($sq, $questionVector, $this->topK * 3, $filters)
                : $this->vectorStore->search($questionVector, $this->topK * 3, $filters);

            foreach ($chunks as $chunk) {
                $key = $chunk->chunk_id;
                if (! isset($allChunks[$key])) {
                    $allChunks[$key] = $chunk;
                }
            }
        }

        $searchTime = (microtime(true) - $t0) * 1000;

        usort($allChunks, fn (object $a, object $b): int => (float) $b->similarity_score <=> (float) $a->similarity_score);
        $chunks = $this->applyDynamicThreshold(array_values($allChunks));
        $chunks = $this->applyMMR($chunks);

        if ($chunks === []) {
            $answer = $this->buildRefusalResponse($session);
            $totalTime = (microtime(true) - $start) * 1000;
            Log::channel(config('rag.logging.channel', 'rag'))->info('RAG pipeline: refusal (no chunks)', [
                'session_id' => $session->id,
                'question_length' => mb_strlen($question),
                'search_time_ms' => round($searchTime, 1),
                'total_time_ms' => round($totalTime, 1),
            ]);

            return $answer;
        }

        $confidence = $this->assessConfidence($chunks);
        $hasOldDocs = $this->hasOldDocuments($chunks);
        $systemPrompt = $this->buildSystemPrompt($confidence, $hasOldDocs);
        $context = $this->reorderForLostInTheMiddle($chunks);

        $t0 = microtime(true);
        $response = $this->llm->complete($systemPrompt, $question, $context, [
            'temperature' => 0.3,
        ]);
        $llmTime = (microtime(true) - $t0) * 1000;

        $sources = $this->buildSources($chunks);
        $message = $this->saveAssistantMessage($session, $response->getContent(), $sources);

        $totalTime = (microtime(true) - $start) * 1000;
        Log::channel(config('rag.logging.channel', 'rag'))->info('RAG pipeline: complete', [
            'session_id' => $session->id,
            'question_length' => mb_strlen($question),
            'chunks_found' => count($chunks),
            'search_time_ms' => round($searchTime, 1),
            'llm_time_ms' => round($llmTime, 1),
            'total_time_ms' => round($totalTime, 1),
            'prompt_tokens' => $response->getPromptTokens(),
            'completion_tokens' => $response->getCompletionTokens(),
            'total_tokens' => $response->getTotalTokens(),
        ]);

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
        set_time_limit(120);
        $start = microtime(true);
        $question = $this->normalizeQuestion($question);
        $session = $this->resolveSession($options['session_id'] ?? null, $options['user_id'] ?? $this->userId);
        $this->saveUserMessage($session, $question);

        $searchQueries = $this->expandQuery($question);
        $autoFilters = $this->extractFiltersFromQuestion($question);

        $allChunks = [];
        $t0 = microtime(true);

        foreach ($searchQueries as $sq) {
            $questionVector = $this->embedder->embed($sq);
            $minThreshold = min($this->similarityThreshold, 0.40);
            $filters = array_merge($autoFilters, $options['document_filter'] ?? []);
            $filters['similarity_threshold'] = $minThreshold;
            $filters['model_name'] = config('rag.embedding.model', 'text-embedding-3-small');

            $chunks = $this->searchMode === 'hybrid'
                ? $this->vectorStore->searchHybrid($sq, $questionVector, $this->topK * 3, $filters)
                : $this->vectorStore->search($questionVector, $this->topK * 3, $filters);

            foreach ($chunks as $chunk) {
                $key = $chunk->chunk_id;
                if (! isset($allChunks[$key])) {
                    $allChunks[$key] = $chunk;
                }
            }
        }

        $searchTime = (microtime(true) - $t0) * 1000;

        usort($allChunks, fn (object $a, object $b): int => (float) $b->similarity_score <=> (float) $a->similarity_score);
        $chunks = $this->applyDynamicThreshold(array_values($allChunks));
        $chunks = $this->applyMMR($chunks);

        if ($chunks === []) {
            $refusal = $this->buildRefusalResponse($session);
            $totalTime = (microtime(true) - $start) * 1000;
            Log::channel(config('rag.logging.channel', 'rag'))->info('RAG pipeline (stream): refusal (no chunks)', [
                'session_id' => $session->id,
                'question_length' => mb_strlen($question),
                'search_time_ms' => round($searchTime, 1),
                'total_time_ms' => round($totalTime, 1),
            ]);
            yield json_encode(['type' => 'answer', 'content' => $refusal['message']['content']]);
            yield json_encode(['type' => 'sources', 'sources' => []]);

            return;
        }

        $confidence = $this->assessConfidence($chunks);
        $hasOldDocs = $this->hasOldDocuments($chunks);
        $systemPrompt = $this->buildSystemPrompt($confidence, $hasOldDocs);
        $context = $this->reorderForLostInTheMiddle($chunks);
        $sources = $this->buildSources($chunks);
        yield json_encode(['type' => 'sources', 'sources' => $sources]);

        $fullContent = '';
        $t0 = microtime(true);
        $stream = $this->llm->completeStream($systemPrompt, $question, $context, ['temperature' => 0.3]);

        foreach ($stream as $chunk) {
            $fullContent .= $chunk;
            yield json_encode(['type' => 'chunk', 'content' => $chunk]);
        }
        $llmTime = (microtime(true) - $t0) * 1000;

        $this->saveAssistantMessage($session, $fullContent, $sources);

        $totalTime = (microtime(true) - $start) * 1000;
        Log::channel(config('rag.logging.channel', 'rag'))->info('RAG pipeline (stream): complete', [
            'session_id' => $session->id,
            'question_length' => mb_strlen($question),
            'chunks_found' => count($chunks),
            'search_time_ms' => round($searchTime, 1),
            'llm_time_ms' => round($llmTime, 1),
            'total_time_ms' => round($totalTime, 1),
            'response_length' => mb_strlen($fullContent),
        ]);
        yield json_encode(['type' => 'done', 'session_id' => $session->id]);
    }

    public function listSessions(?string $userId = null): array
    {
        $query = ChatSession::orderByDesc('last_activity_at');

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return $query->paginate(20)->toArray();
    }

    public function getSession(string $id, ?string $userId = null): array
    {
        $query = ChatSession::with('messages')->where('id', $id);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $session = $query->firstOrFail();

        return $session->toArray();
    }

    public function deleteSession(string $id, ?string $userId = null): void
    {
        $query = ChatSession::where('id', $id);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $session = $query->firstOrFail();
        $session->messages()->delete();
        $session->delete();
    }

    private function extractFiltersFromQuestion(string $question): array
    {
        $filters = [];

        $datePatterns = [
            '/\b(Q[1-4]\s*\d{4})\b/i',
            '/\b(\d{4})\b/',
            '/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4}\b/i',
        ];

        foreach ($datePatterns as $pattern) {
            if (preg_match($pattern, $question, $matches)) {
                $dateStr = $matches[1];
                if (preg_match('/^Q[1-4]/i', $dateStr)) {
                    $quarter = (int) substr($dateStr, 1, 1);
                    $year = (int) trim(substr($dateStr, strpos($dateStr, ' ') + 1));
                    $filters['date_from'] = "{$year}-".str_pad((string) (($quarter - 1) * 3 + 1), 2, '0', STR_PAD_LEFT).'-01';
                    $filters['date_to'] = "{$year}-".str_pad((string) ($quarter * 3), 2, '0', STR_PAD_LEFT).'-31';
                } elseif (preg_match('/^\d{4}$/', $dateStr)) {
                    $filters['date_from'] = "{$dateStr}-01-01";
                    $filters['date_to'] = "{$dateStr}-12-31";
                }
                break;
            }
        }

        return $filters;
    }

    private function expandQuery(string $question): array
    {
        if (! $this->queryExpansionEnabled) {
            return [$question];
        }

        try {
            $prompt = "You are a search query optimizer. Generate {$this->numExpansionQueries} different reformulations of the given question to improve document retrieval. Return ONE reformulation per line, no numbering, no extra text.\n\nQuestion: {$question}";

            $response = $this->llm->complete(
                'You generate search queries. Return only the queries, one per line.',
                $prompt,
                [],
                ['temperature' => 0.3, 'max_tokens' => 500],
            );

            $lines = array_filter(
                explode("\n", $response->getContent()),
                fn (string $line): bool => trim($line) !== '',
            );

            $queries = array_slice(array_values($lines), 0, $this->numExpansionQueries);
            array_unshift($queries, $question);

            return $queries;
        } catch (\Throwable $e) {
            Log::channel(config('rag.logging.channel', 'rag'))->warning('Query expansion failed, using original question', [
                'error' => $e->getMessage(),
            ]);

            return [$question];
        }
    }

    private function reorderForLostInTheMiddle(array $chunks): array
    {
        if (count($chunks) <= 2) {
            return $chunks;
        }

        $ordered = [$chunks[0]];
        $remaining = array_slice($chunks, 1);
        $middle = (int) ceil(count($remaining) / 2);

        $ordered = array_merge(
            [$chunks[0]],
            array_slice($remaining, 0, $middle - 1),
            [$chunks[count($chunks) - 1]],
            array_slice($remaining, $middle - 1),
        );

        return $ordered;
    }

    private function applyDynamicThreshold(array $chunks): array
    {
        if ($chunks === []) {
            return [];
        }

        $scores = array_map(fn (object $c): float => (float) $c->similarity_score, $chunks);
        rsort($scores);

        if (count($scores) <= 1) {
            return array_slice($chunks, 0, $this->topK);
        }

        $maxGap = 0.0;
        $gapIndex = 0;
        for ($i = 0; $i < count($scores) - 1; $i++) {
            $gap = $scores[$i] - $scores[$i + 1];
            if ($gap > $maxGap) {
                $maxGap = $gap;
                $gapIndex = $i;
            }
        }

        $cutoff = $this->similarityThreshold;
        if ($maxGap > 0.15) {
            $cutoff = max($cutoff, $scores[$gapIndex + 1]);
        } else {
            $scaled = (float) $scores[0] * 0.85;
            $cutoff = max($cutoff, $scaled);
        }

        $filtered = array_values(
            array_filter($chunks, fn (object $c): bool => (float) $c->similarity_score >= $cutoff)
        );

        $filtered = array_slice($filtered, 0, $this->topK);

        if ($filtered === [] && $chunks !== []) {
            return array_slice($chunks, 0, min(1, $this->topK));
        }

        return $filtered;
    }

    private function applyMMR(array $chunks): array
    {
        if (! $this->mmrEnabled || count($chunks) <= 1) {
            return $chunks;
        }

        $topK = min($this->topK, count($chunks));
        $selected = [];
        $candidates = $chunks;

        $selected[] = array_shift($candidates);

        while (count($selected) < $topK && $candidates !== []) {
            $bestIdx = -1;
            $bestScore = -INF;

            foreach ($candidates as $i => $cand) {
                $simScore = (float) $cand->similarity_score;
                $maxSimToSelected = 0.0;

                foreach ($selected as $sel) {
                    $penalty = $cand->document_id === $sel->document_id ? 1.0 : 0.0;
                    if ($penalty > $maxSimToSelected) {
                        $maxSimToSelected = $penalty;
                    }
                }

                $mmr = $this->mmrLambda * $simScore - (1.0 - $this->mmrLambda) * $maxSimToSelected;

                if ($mmr > $bestScore) {
                    $bestScore = $mmr;
                    $bestIdx = $i;
                }
            }

            if ($bestIdx !== -1) {
                $selected[] = $candidates[$bestIdx];
                array_splice($candidates, $bestIdx, 1);
            } else {
                break;
            }
        }

        return $selected;
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

    private function resolveSession(?string $sessionId, ?string $userId = null): ChatSession
    {
        if ($sessionId !== null) {
            $query = ChatSession::where('id', $sessionId);

            if ($userId !== null) {
                $query->where('user_id', $userId);
            }

            $session = $query->first();
            if ($session === null) {
                throw new \RuntimeException('Chat session not found.');
            }
            if ($session->trashed()) {
                throw new \RuntimeException('Chat session has been deleted.');
            }
            if ($session->last_activity_at < now()->subHours(24)) {
                throw new \RuntimeException('Chat session has expired. Please start a new chat.');
            }
            $session->update(['last_activity_at' => now()]);

            return $session;
        }

        return ChatSession::create([
            'title' => 'New Chat',
            'last_activity_at' => now(),
            'user_id' => $userId,
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
        if ($session->title === 'New Chat') {
            $session->update([
                'title' => mb_substr($content, 0, 50),
            ]);
        }
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
        $content = 'I cannot answer this question based on the available documents. Try asking about the content of your uploaded documents, or upload documents containing relevant information.';
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

    private function buildSystemPrompt(string $confidence, bool $hasOldDocuments = false): string
    {
        $prompt = 'You are a precise document-answering assistant. Follow these rules strictly:

1. Answer ONLY using the provided context. Do NOT use prior knowledge.
2. If the context does not fully answer the question, state what you know and what information is missing.
3. NEVER make up or hallucinate information. If uncertain, say so.
4. Cite sources by document title for every factual claim.
5. Respond in the SAME LANGUAGE as the user\'s question.';

        if ($confidence === 'low') {
            $prompt .= "\n\n6. Note: The available information may be limited. Acknowledge uncertainty clearly and suggest what additional information would help.";
        }

        if ($hasOldDocuments) {
            $prompt .= "\n\n7. Important: Some source documents are over a year old. Note this when the information may be time-sensitive.";
        }

        return $prompt;
    }

    private function hasOldDocuments(array $chunks): bool
    {
        $oneYearAgo = now()->subYear();

        foreach ($chunks as $chunk) {
            if (isset($chunk->document_created_at) && Carbon::parse($chunk->document_created_at)->lt($oneYearAgo)) {
                return true;
            }
        }

        return false;
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
