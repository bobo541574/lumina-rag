<?php

declare(strict_types=1);

namespace Modules\LLMModule\Contracts;

interface LLMResponseInterface
{
    public function getContent(): string;

    public function getPromptTokens(): int;

    public function getCompletionTokens(): int;

    public function getTotalTokens(): int;

    public function getModel(): string;

    public function getFinishReason(): ?string;
}
