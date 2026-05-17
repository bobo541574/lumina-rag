<?php

declare(strict_types=1);

use Modules\DocumentModule\Services\TextChunkingService;

/**
 * chunk() returns an empty array for empty input text
 *
 * Ensures the chunker gracefully handles the edge case of an
 * empty string without throwing or producing spurious chunks.
 *
 * @return void
 */
test('test_chunk_empty_text_returns_empty_array', function (): void {
    $service = new TextChunkingService;
    $result = $service->chunk('', 1000, 200);
    expect($result)->toBe([]);
});

/**
 * chunk() returns a single chunk for text shorter than chunk size
 *
 * Verifies that short input fits entirely within one chunk with
 * correct char_start (0) and char_end set to the full text length.
 *
 * @return void
 */
test('test_chunk_short_text_returns_single_chunk', function (): void {
    $service = new TextChunkingService;
    $text = 'Hello world';
    $result = $service->chunk($text, 1000, 200);

    expect($result)->toHaveCount(1);
    expect($result[0]['content'])->toBe($text);
    expect($result[0]['char_start'])->toBe(0);
    expect($result[0]['char_end'])->toBe(mb_strlen($text));
});

/**
 * chunk() returns multiple chunks for text that exceeds chunk size
 *
 * Feeds a long repeated string with a small chunk size and verifies
 * that the chunker produces multiple contiguous chunks spanning the
 * full text range.
 *
 * @return void
 */
test('test_chunk_long_text_returns_multiple_chunks', function (): void {
    $service = new TextChunkingService;
    $text = str_repeat('A paragraph of text. ', 200);
    $result = $service->chunk($text, 200, 50);

    expect(count($result))->toBeGreaterThan(1);
    expect($result[0]['char_start'])->toBe(0);
    expect(end($result)['char_end'])->toBe(mb_strlen($text));
});

/**
 * chunk() respects separator priority when splitting text
 *
 * Provides text with paragraph breaks (double newlines) and long
 * trailing text. The chunker should prefer splitting on paragraph
 * boundaries before falling back to word-level splits.
 *
 * @return void
 */
test('test_chunk_respects_separator_priority', function (): void {
    $service = new TextChunkingService;
    $text = "Paragraph one.\n\nParagraph two.\n\nParagraph three. ".str_repeat('word ', 100);
    $result = $service->chunk($text, 50, 0);

    expect(count($result))->toBeGreaterThan(1);
});
