<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Services;

use Modules\DocumentModule\Contracts\TextChunkingServiceInterface;

class TextChunkingService implements TextChunkingServiceInterface
{
    private array $separators;

    public function __construct(?array $separators = null)
    {
        $this->separators = $separators ?? ["\n\n", "\n", '.', ',', ' ', ''];
    }

    public function chunk(string $text, int $chunkSize = 1000, int $overlap = 200): array
    {
        if ($text === '') {
            return [];
        }

        $length = mb_strlen($text);

        if ($length <= $chunkSize) {
            return [
                [
                    'content' => $text,
                    'char_start' => 0,
                    'char_end' => $length,
                ],
            ];
        }

        $chunks = [];
        $start = 0;

        while ($start < $length) {
            $end = $start + $chunkSize;

            if ($end >= $length) {
                $chunks[] = [
                    'content' => mb_substr($text, $start),
                    'char_start' => $start,
                    'char_end' => $length,
                ];
                break;
            }

            $splitAt = $this->findSplitPoint($text, $start, $end, $overlap);

            $chunkEnd = min($splitAt, $end);

            $chunks[] = [
                'content' => mb_substr($text, $start, $chunkEnd - $start),
                'char_start' => $start,
                'char_end' => $chunkEnd,
            ];

            $nextStart = $chunkEnd - $overlap;
            $start = max($nextStart, $start + 1);

            if ($start >= $length) {
                break;
            }
        }

        return $chunks;
    }

    private function findSplitPoint(string $text, int $start, int $end, int $overlap): int
    {
        $segment = mb_substr($text, $start, $end - $start);

        foreach ($this->separators as $separator) {
            if ($separator === '') {
                return $end;
            }

            $pos = mb_strrpos($segment, $separator);

            if ($pos !== false && $pos > $overlap) {
                return $start + $pos + mb_strlen($separator);
            }
        }

        return $end;
    }
}
