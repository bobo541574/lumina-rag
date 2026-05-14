<?php

declare(strict_types=1);

namespace Modules\LLMModule\Contracts;

use Generator;

interface LLMProviderInterface
{
    public function complete(string $systemPrompt, string $userPrompt, array $options = []): LLMResponseInterface;

    public function completeStream(string $systemPrompt, string $userPrompt, array $options = []): Generator;

    public function getModelName(): string;

    public function countTokens(string $text): int;
}
