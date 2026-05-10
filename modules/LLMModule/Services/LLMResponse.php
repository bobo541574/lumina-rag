<?php

declare(strict_types=1);

namespace Modules\LLMModule\Services;

use Modules\LLMModule\Contracts\LLMResponseInterface;

class LLMResponse implements LLMResponseInterface
{
    private string $content;

    private int $promptTokens;

    private int $completionTokens;

    private int $totalTokens;

    private string $model;

    private ?string $finishReason;

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

    public function getContent(): string
    {
        return $this->content;
    }

    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    public function getTotalTokens(): int
    {
        return $this->totalTokens;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getFinishReason(): ?string
    {
        return $this->finishReason;
    }
}
