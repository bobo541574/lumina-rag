<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Services;

use Modules\DocumentModule\Contracts\TextChunkingServiceInterface;

class TextChunkingService implements TextChunkingServiceInterface
{
    private array $separators;

    private const HEADING_PATTERN = '/^#{1,6}\s/m';

    public function __construct(?array $separators = null)
    {
        $this->separators = $separators ?? ["\n\n", "\n", '.', ',', ' ', ''];
    }

    public function chunk(string $text, int $chunkSize = 1000, int $overlap = 200): array
    {
        if ($text === '') {
            return [];
        }

        $sections = $this->splitByHeadings($text);
        $chunks = [];
        $currentHeading = null;

        foreach ($sections as $section) {
            $sectionText = $section['text'];
            $sectionHeading = $section['heading'] ?? $currentHeading;

            if ($section['heading'] !== null) {
                $currentHeading = $section['heading'];
            }

            $sectionChunks = $this->chunkText($sectionText, $chunkSize, $overlap, $sectionHeading);
            $chunks = array_merge($chunks, $sectionChunks);
        }

        return $chunks;
    }

    private function splitByHeadings(string $text): array
    {
        $lines = explode("\n", $text);
        $sections = [];
        $currentHeading = null;
        $currentText = '';

        foreach ($lines as $line) {
            if (preg_match(self::HEADING_PATTERN, $line)) {
                if ($currentText !== '') {
                    $sections[] = [
                        'text' => rtrim($currentText, "\n"),
                        'heading' => $currentHeading,
                    ];
                    $currentText = '';
                }
                $currentHeading = trim(preg_replace('/^#+\s/', '', $line));

                continue;
            }
            $currentText .= $line."\n";
        }

        if ($currentText !== '') {
            $sections[] = [
                'text' => rtrim($currentText, "\n"),
                'heading' => $currentHeading,
            ];
        }

        if ($sections === []) {
            $sections[] = [
                'text' => $text,
                'heading' => null,
            ];
        }

        return $sections;
    }

    private function chunkText(string $text, int $chunkSize, int $overlap, ?string $heading = null): array
    {
        if ($text === '') {
            return [];
        }

        $length = mb_strlen($text);

        if ($length <= $chunkSize) {
            $content = $heading ? "[{$heading}]\n{$text}" : $text;

            return [
                [
                    'content' => $content,
                    'char_start' => 0,
                    'char_end' => $length,
                    'page_number' => null,
                ],
            ];
        }

        $chunks = [];
        $start = 0;

        while ($start < $length) {
            $end = $start + $chunkSize;

            if ($end >= $length) {
                $content = mb_substr($text, $start);
                if ($heading) {
                    $content = "[{$heading}]\n{$content}";
                }
                $chunks[] = [
                    'content' => $content,
                    'char_start' => $start,
                    'char_end' => $length,
                    'page_number' => null,
                ];
                break;
            }

            $splitAt = $this->findSplitPoint($text, $start, $end, $overlap);
            $chunkEnd = min($splitAt, $end);

            $content = mb_substr($text, $start, $chunkEnd - $start);
            if ($heading) {
                $content = "[{$heading}]\n{$content}";
            }
            $chunks[] = [
                'content' => $content,
                'char_start' => $start,
                'char_end' => $chunkEnd,
                'page_number' => null,
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
