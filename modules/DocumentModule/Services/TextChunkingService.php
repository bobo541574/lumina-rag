<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Services;

use Modules\DocumentModule\Contracts\TextChunkingServiceInterface;

class TextChunkingService implements TextChunkingServiceInterface
{
    private array $separators;

    public function __construct()
    {
        $this->separators = ["\n\n", "\n", '.', ',', ' ', ''];
    }

    public function chunk(string $text, int $chunkSize = 1000, int $overlap = 200): array
    {
        if ($text === '') {
            return [];
        }

        if (mb_strlen($text) <= $chunkSize) {
            return [
                [
                    'content' => $text,
                    'char_start' => 0,
                    'char_end' => mb_strlen($text),
                ],
            ];
        }

        $chunks = [];
        $start = 0;
        $length = mb_strlen($text);

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

            $splitAt = $this->findSplitPoint($text, $start, $end);

            $chunks[] = [
                'content' => mb_substr($text, $start, $splitAt - $start),
                'char_start' => $start,
                'char_end' => $splitAt,
            ];

            $start = $splitAt - $overlap;

            if ($start < 0) {
                $start = 0;
            }
        }

        return $chunks;
    }

    private function findSplitPoint(string $text, int $start, int $end): int
    {
        $segment = mb_substr($text, $start, $end - $start);

        foreach ($this->separators as $separator) {
            if ($separator === '') {
                return $end;
            }

            $pos = mb_strrpos($segment, $separator);

            if ($pos !== false) {
                return $start + $pos + mb_strlen($separator);
            }
        }

        return $end;
    }
}
