<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Contracts;

use Modules\SettingsModule\Models\AiModel;

interface AiModelServiceInterface
{
    public function getAll(?string $type = null): array;

    public function getActiveModels(string $type): array;

    public function find(string $id): AiModel;

    public function create(array $data): AiModel;

    public function update(string $id, array $data): AiModel;

    public function delete(string $id): void;

    public function getValidationRules(string $type): array;
}
