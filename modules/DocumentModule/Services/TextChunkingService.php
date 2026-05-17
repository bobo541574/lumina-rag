<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Services;

use Modules\DocumentModule\Contracts\TextChunkingServiceInterface;

/**
 * Text Chunking Service
 *
 * Implements a recursive character text splitter that divides document text into
 * overlapping chunks. Splits by heading boundaries first (Markdown-style # headers),
 * then within each section uses a priority-ordered separator list
 * ("\n\n" → "\n" → "." → "," → " " → character) to find optimal split points.
 * Ensures chunks stay within the target character size while preserving overlap
 * for context continuity.
 *
 * @param  array|null  $separators  Ordered list of separators for split point search,
 *                                  from most to least preferred. Example: ["\n\n", "\n", ".", ",", " ", ""]
 */
class TextChunkingService implements TextChunkingServiceInterface
{
    private array $separators;

    private const HEADING_PATTERN = '/^#{1,6}\s/m';

    public function __construct(?array $separators = null)
    {
        $this->separators = $separators ?? ["\n\n", "\n", '.', ',', ' ', ''];
    }

    /**
     * Split text into chunks
     *
     * First splits the text by Markdown headings, then chunks each section
     * independently. Each chunk includes its section heading as a prefix
     * (e.g., "[Section Title]\ncontent"). Returns an array of chunk data
     * with content, character offsets, and optional page number.
     *
     * @param  string  $text  The text to split into chunks. Example: "Executive Summary\n\nThe project achieved..."
     * @param  int  $chunkSize  Target character length per chunk. Example: 1000
     * @param  int  $overlap  Character overlap between consecutive chunks. Example: 200
     * @return array Array of chunk arrays, each with keys: content, char_start, char_end, page_number.
     *               Example: [["content" => "[Executive Summary]\nThe project...", "char_start" => 0, "char_end" => 1000, "page_number" => null]]
     */
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

    /**
     * Split text into sections by Markdown headings
     *
     * Parses the text line-by-line, looking for lines matching "# Heading" patterns.
     * Each heading starts a new section. Text before the first heading is assigned
     * a null heading. Returns an ordered list of sections with optional heading names.
     *
     * @param  string  $text  The full document text. Example: "# Intro\n\nText...\n## Details\n\nMore text..."
     * @return array Array of section arrays, each with "text" and "heading" (string|null) keys.
     *               Example: [["text" => "Text...", "heading" => null], ["text" => "More text...", "heading" => "Details"]]
     */
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

    /**
     * Chunk a single section of text into overlapping pieces
     *
     * If the text fits within chunkSize, returns a single chunk with the optional
     * heading prefix. Otherwise, iteratively finds split points using findSplitPoint,
     * creates chunks with configured overlap, and prepends the heading to each.
     *
     * @param  string  $text  The section text to chunk. Example: "Paragraph one.\n\nParagraph two..."
     * @param  int  $chunkSize  Target character length per chunk. Example: 1000
     * @param  int  $overlap  Character overlap between consecutive chunks. Example: 200
     * @param  string|null  $heading  Optional section heading to prepend. Example: "Executive Summary"
     * @return array Array of chunk arrays with keys: content, char_start, char_end, page_number.
     *               Example: [["content" => "[Executive Summary]\nParagraph...", "char_start" => 0, "char_end" => 1000, "page_number" => null]]
     */
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
                    'section' => $heading,
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
                    'section' => $heading,
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
                'section' => $heading,
            ];

            $nextStart = $chunkEnd - $overlap;
            $start = max($nextStart, $start + 1);

            if ($start >= $length) {
                break;
            }
        }

        return $chunks;
    }

    /**
     * Find the optimal character position to split a chunk
     *
     * Searches the segment between start and end for the rightmost occurrence of
     * each separator (in priority order). The split point must be at least
     * $overlap characters from the start to maintain minimum overlap. Falls back
     * to the end position if no suitable separator is found.
     *
     * @param  string  $text  The full document text. Example: "Paragraph one.\n\nParagraph two..."
     * @param  int  $start  The start offset of the current segment. Example: 0
     * @param  int  $end  The end offset of the current segment. Example: 1000
     * @param  int  $overlap  Minimum distance from start to split point. Example: 200
     * @return int The character offset to split at.
     *             Example: 950 (split after a newline separator)
     */
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
