<?php

declare(strict_types=1);

namespace Modules\LLMModule\Services;

use Modules\LLMModule\Contracts\LLMResponseInterface;

/**
 * LLM Response
 *
 * Value object representing the result of an LLM completion call. Contains
 * the generated content, token usage statistics (prompt, completion, total),
 * the model identifier, and the reason the generation finished.
 *
 * Created by LLM provider classes (OpenAILLMProvider, OllamaLLMProvider)
 * and consumed by LLMService and the RAG pipeline for downstream processing,
 * logging, and cost tracking.
 *
 * @param string $content The generated text. Example: "The answer is 42."
 * @param int $promptTokens Tokens used in the prompt. Example: 150
 * @param int $completionTokens Tokens used in the response. Example: 50
 * @param int $totalTokens Total tokens (prompt + completion). Example: 200
 * @param string $model Model identifier. Example: "gpt-4o"
 * @param string|null $finishReason Reason completion ended. Example: "stop"
 */
class LLMResponse implements LLMResponseInterface
{
    /** @var string Generated text content */
    private string $content;

    /** @var int Tokens used in the prompt */
    private int $promptTokens;

    /** @var int Tokens used in the completion */
    private int $completionTokens;

    /** @var int Total tokens (prompt + completion) */
    private int $totalTokens;

    /** @var string Model identifier */
    private string $model;

    /** @var string|null Reason the completion finished */
    private ?string $finishReason;

    /**
     * @param  string  $content  Generated response text. Example: "Project Orion was a study..."
     * @param  int  $promptTokens  Prompt token count. Example: 245
     * @param  int  $completionTokens  Completion token count. Example: 87
     * @param  int  $totalTokens  Total token count. Example: 332
     * @param  string  $model  Model identifier. Example: "gpt-4o"
     * @param  string|null  $finishReason  Reason for finishing. Example: "stop"
     */
    public function __construct(
        string $content,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
        string $model,
        ?string $finishReason = null,
    ) {
        $this->content = $content;
        $this->promptTokens = $promptTokens;
        $this->completionTokens = $completionTokens;
        $this->totalTokens = $totalTokens;
        $this->model = $model;
        $this->finishReason = $finishReason;
    }

    /**
     * Get the generated text content.
     *
     * @return string Response content. Example: "Project Orion was a study..."
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Get the number of tokens used in the prompt.
     *
     * @return int Prompt token count. Example: 245
     */
    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    /**
     * Get the number of tokens used in the completion.
     *
     * @return int Completion token count. Example: 87
     */
    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    /**
     * Get the total token usage (prompt + completion).
     *
     * @return int Total token count. Example: 332
     */
    public function getTotalTokens(): int
    {
        return $this->totalTokens;
    }

    /**
     * Get the model identifier that generated the response.
     *
     * @return string Model name. Example: "gpt-4o"
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Get the reason why the completion finished.
     *
     * @return string|null Finish reason. Example: "stop", "length", or null if unknown
     */
    public function getFinishReason(): ?string
    {
        return $this->finishReason;
    }
}
