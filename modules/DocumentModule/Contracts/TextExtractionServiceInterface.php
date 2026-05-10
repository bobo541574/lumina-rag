<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Contracts;

interface TextExtractionServiceInterface
{
    public function extract(string $filePath, string $mimeType): string;
}
