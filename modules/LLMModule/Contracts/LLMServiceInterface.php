<?php

declare(strict_types=1);

namespace Modules\LLMModule\Contracts;

use Generator;

/**
 * LLM Service Interface
 *
 * Defines the contract for LLM text completion services. Implementations
 * wrap an LLM provider (OpenAI, Ollama, etc.) with prompt assembly, context
 * management, and token counting. The primary consumer is the RAG pipeline.
 *
 * Supports both synchronous (complete) and streaming (completeStream)
 * responses. Context is automatically assembled and truncated to fit
 * within token budgets.
 *
 * @method LLMResponseInterface complete(string $systemPrompt, string $userPrompt, array $context, array $options) Synchronous completion. Example: $llm->complete("You are...", "What is X?", $chunks)
 * @method Generator completeStream(string $systemPrompt, string $userPrompt, array $context, array $options) Streaming completion. Example: foreach ($llm->completeStream(...) as $chunk) { echo $chunk; }
 * @method int countTokens(string $text) Rough token count. Example: $llm->countTokens("Hello world")
 *
 * @throws \RuntimeException When the LLM provider returns an error
 */
interface LLMServiceInterface
{
    /**
     * Perform a synchronous LLM completion.
     *
     * Assembles the context into the user prompt, sends it to the provider,
     * and returns the full response object.
     *
     * @param  string  $systemPrompt  System-level instructions. Example: "You are a helpful assistant. Answer based ONLY on the provided context."
     * @param  string  $userPrompt  The user's question. Example: "What is Project Orion?"
     * @param  array  $context  Array of chunk objects with content, document_title, page_number, similarity_score. Example: [["content" => "...", "document_title" => "Report.pdf"]]
     * @param  array  $options  Provider options (temperature, max_tokens, model). Example: ["temperature" => 0.3, "max_tokens" => 4096]
     * @return LLMResponseInterface Response with content and token usage. Example: LLMResponse with content "Project Orion is..."
     *
     * @throws \RuntimeException When the provider returns an error or empty response
     * @throws \InvalidArgumentException When systemPrompt is empty
     */
    public function complete(string $systemPrompt, string $userPrompt, array $context, array $options = []): LLMResponseInterface;

    /**
     * Perform a streaming LLM completion.
     *
     * Returns a Generator that yields content chunks as they arrive from
     * the provider. Used for real-time streaming responses to the user.
     *
     * @param  string  $systemPrompt  System-level instructions. Example: "You are a helpful assistant..."
     * @param  string  $userPrompt  The user's question. Example: "What is Project Orion?"
     * @param  array  $context  Array of chunk objects. Example: [["content" => "...", "document_title" => "Report.pdf"]]
     * @param  array  $options  Provider options. Example: ["temperature" => 0.3]
     * @return Generator Yields strings of content. Example: foreach ($gen as $chunk) { echo $chunk; }
     *
     * @throws \RuntimeException When the provider returns an error during streaming
     */
    public function completeStream(string $systemPrompt, string $userPrompt, array $context, array $options = []): Generator;

    /**
     * Count tokens in a text string.
     *
     * Uses a rough approximation (character count / 4) rather than a
     * tokenizer. Suitable for budget estimation in context assembly.
     *
     * @param  string  $text  Input text. Example: "This is a sample sentence."
     * @return int Estimated token count. Example: 8
     */
    public function countTokens(string $text): int;
}
