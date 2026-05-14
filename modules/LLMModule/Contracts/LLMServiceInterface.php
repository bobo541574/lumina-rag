<?php

declare(strict_types=1);

namespace Modules\LLMModule\Contracts;

use Generator;

interface LLMServiceInterface
{
    public function complete(string $systemPrompt, string $userPrompt, array $context, array $options = []): LLMResponseInterface;

    public function completeStream(string $systemPrompt, string $userPrompt, array $context, array $options = []): Generator;
}
