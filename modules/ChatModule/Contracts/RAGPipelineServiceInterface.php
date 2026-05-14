<?php

declare(strict_types=1);

namespace Modules\ChatModule\Contracts;

use Generator;

interface RAGPipelineServiceInterface
{
    public function ask(string $question, array $options = []): array;

    public function askStream(string $question, array $options = []): Generator;

    public function listSessions(?string $userId = null): array;

    public function getSession(string $id, ?string $userId = null): array;

    public function deleteSession(string $id, ?string $userId = null): void;
}
