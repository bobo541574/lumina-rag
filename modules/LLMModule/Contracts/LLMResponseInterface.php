<?php

declare(strict_types=1);

namespace Modules\LLMModule\Contracts;

/**
 * LLM Response Interface
 *
 * Defines the contract for responses returned by LLM completion calls.
 * Provides access to the generated content, token usage statistics, and
 * metadata about the model and finish reason.
 *
 * Implementations are created by LLM provider classes (OpenAILLMProvider,
 * OllamaLLMProvider) and consumed by the LLMService and RAG pipeline.
 *
 * @method string getContent() Generated text content. Example: "The answer is 42."
 * @method int getPromptTokens() Tokens used in the prompt. Example: 150
 * @method int getCompletionTokens() Tokens used in the response. Example: 50
 * @method int getTotalTokens() Total tokens (prompt + completion). Example: 200
 * @method string getModel() Model identifier. Example: "gpt-4o"
 * @method string|null getFinishReason() Reason completion ended. Example: "stop"
 */
interface LLMResponseInterface
{
    /**
     * Get the generated text content.
     *
     * @return string The LLM's response text. Example: "Project Orion was a study..."
     */
    public function getContent(): string;

    /**
     * Get the number of tokens used in the prompt.
     *
     * @return int Prompt token count. Example: 245
     */
    public function getPromptTokens(): int;

    /**
     * Get the number of tokens used in the completion.
     *
     * @return int Completion token count. Example: 87
     */
    public function getCompletionTokens(): int;

    /**
     * Get the total token usage (prompt + completion).
     *
     * @return int Total token count. Example: 332
     */
    public function getTotalTokens(): int;

    /**
     * Get the model identifier that generated the response.
     *
     * @return string Model name. Example: "gpt-4o"
     */
    public function getModel(): string;

    /**
     * Get the reason why the completion finished.
     *
     * @return string|null Finish reason. Example: "stop", "length", or null if unknown
     */
    public function getFinishReason(): ?string;
}
