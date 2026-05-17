<?php

declare(strict_types=1);

namespace Modules\ChatModule\Contracts;

use Generator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * RAG Pipeline Service Interface
 *
 * Defines the contract for orchestrating the full RAG (Retrieval-Augmented Generation)
 * flow: embedding, vector/hybrid search, filtering, and LLM-based answer generation.
 * Implementations handle both synchronous and streaming question answering, as well
 * as chat session lifecycle management (list, get, delete).
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
     * @throws \RuntimeException When session not found, expired, or message limit reached
     *                           Example: $service->ask('question', ['session_id' => 'invalid']) → RuntimeException
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
     * @throws \RuntimeException When session not found, expired, or message limit reached
     *                           Example: $service->askStream('question', ['session_id' => 'invalid']) → RuntimeException
     */
    public function askStream(string $question, array $options = []): Generator;

    /**
     * List chat sessions with pagination
     *
     * Returns a paginated list of chat sessions ordered by last activity descending.
     * Optionally scoped to a specific user. Each page contains 20 sessions.
     *
     * @param  string|null  $userId  Optional ULID to filter sessions by user. Example: "01J..."
     * @param  int  $page  Page number (1-based). Example: 1
     * @return array{data: array, current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}
     *                                                                                                                        Paginated session list. Example: ["data" => [...], "current_page" => 1, "last_page" => 3, "total" => 50]
     */
    public function listSessions(?string $userId = null, int $page = 1): array;

    /**
     * Get a single session with all messages
     *
     * Retrieves a chat session by ULID, including all associated messages.
     * Optionally scoped to a specific user for authorization.
     *
     * @param  string  $id  The session ULID. Example: "01J..."
     * @param  string|null  $userId  Optional ULID to verify session ownership. Example: "01J..."
     * @return array The session array with loaded messages relation
     *               Example: ["id" => "01J...", "title" => "New Chat", "messages" => [...]]
     *
     * @throws ModelNotFoundException When session is not found
     *                                Example: $service->getSession('nonexistent') → ModelNotFoundException
     */
    public function getSession(string $id, ?string $userId = null): array;

    /**
     * Delete a session and its messages
     *
     * Soft-deletes both the session and all associated messages.
     * Optionally scoped to a specific user for authorization.
     *
     * @param  string  $id  The session ULID. Example: "01J..."
     * @param  string|null  $userId  Optional ULID to verify session ownership. Example: "01J..."
     *
     * @throws ModelNotFoundException When session is not found
     *                                Example: $service->deleteSession('nonexistent') → ModelNotFoundException
     */
    public function deleteSession(string $id, ?string $userId = null): void;
}
