<?php

declare(strict_types=1);

namespace Modules\ChatModule\Contracts;

use Generator;
use Modules\ChatModule\Exceptions\ChatSessionException;

/**
 * RAG Pipeline Service Interface
 *
 * Defines the contract for orchestrating the full RAG (Retrieval-Augmented Generation)
 * flow: embedding, vector/hybrid search, filtering, and LLM-based answer generation.
 * Implementations handle both synchronous and streaming question answering; chat
 * session CRUD lives in SessionManager.
 */
interface RAGPipelineServiceInterface
{
    /**
     * Answer a question synchronously
     *
     * Embeds the question, searches for relevant chunks, applies filtering and
     * diversity reranking, then calls the LLM to generate an answer. Persists
     * both user and assistant messages to the session.
     *
     * @param  string  $question  The user's natural-language question. Example: "What is the revenue for Q3?"
     * @param  array  $options  Optional overrides for session_id, document_filter, user_id, llm_model_id. Example: ["session_id" => "01J...", "document_filter" => []]
     * @return array{session_id: string, message: array} The assistant response with session_id, message content, and sources
     *                                                   Example: ["session_id" => "01J...", "message" => ["role" => "assistant", "content" => "Revenue was...", "sources" => [...]]]
     *
     * @throws \InvalidArgumentException When question is empty or exceeds max length
     *                                   Example: $service->ask('') → InvalidArgumentException("Question cannot be empty")
     * @throws ChatSessionException When session not found, expired, or message limit reached
     *                              Example: $service->ask('question', ['session_id' => 'invalid']) → ChatSessionException
     */
    public function ask(string $question, array $options = []): array;

    /**
     * Answer a question with streaming response
     *
     * Same pipeline as ask() but yields JSON-encoded events (status, chunk, sources, done)
     * for Server-Sent Event delivery. The LLM response is streamed token by token.
     *
     * @param  string  $question  The user's natural-language question. Example: "What is the revenue for Q3?"
     * @param  array  $options  Optional overrides for session_id, document_filter, user_id, llm_model_id. Example: ["session_id" => "01J...", "document_filter" => []]
     * @return Generator Yields JSON-encoded event strings: status, sources, chunk, done
     *                   Example: yield json_encode(['type' => 'chunk', 'content' => 'Revenue'])
     *
     * @throws \InvalidArgumentException When question is empty or exceeds max length
     *                                   Example: $service->askStream('') → InvalidArgumentException
     * @throws ChatSessionException When session not found, expired, or message limit reached
     *                              Example: $service->askStream('question', ['session_id' => 'invalid']) → ChatSessionException
     */
    public function askStream(string $question, array $options = []): Generator;
}
