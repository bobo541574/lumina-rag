<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Contracts;

use Illuminate\Http\UploadedFile;
use Modules\DocumentModule\Models\Document;

interface DocumentServiceInterface
{
    public function listDocuments(array $filters = [], ?string $userId = null): array;

    public function upload(UploadedFile $file, ?string $title, ?string $userId, ?string $embeddingModel, ?string $embeddingModelId, ?string $reportDate, ?string $project): Document;

    public function updateDocument(string $id, array $data, ?string $userId): Document;

    public function deleteDocument(string $id, ?string $userId): void;

    public function retryDocument(string $id, ?string $userId): Document;
}
