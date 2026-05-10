<?php

declare(strict_types=1);

namespace Modules\ChatModule\Contracts;

use Generator;

interface RAGPipelineServiceInterface
{
    public function ask(string $question, array $options): array;

    public function askStream(string $question, array $options): Generator;
}
