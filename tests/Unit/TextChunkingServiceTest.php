<?php

declare(strict_types=1);

use Modules\DocumentModule\Services\TextChunkingService;

test('test_chunk_empty_text_returns_empty_array', function (): void {
    $service = new TextChunkingService;
    $result = $service->chunk('', 1000, 200);
    expect($result)->toBe([]);
});

test('test_chunk_short_text_returns_single_chunk', function (): void {
    $service = new TextChunkingService;
    $text = 'Hello world';
    $result = $service->chunk($text, 1000, 200);

    expect($result)->toHaveCount(1);
    expect($result[0]['content'])->toBe($text);
    expect($result[0]['char_start'])->toBe(0);
    expect($result[0]['char_end'])->toBe(mb_strlen($text));
});

test('test_chunk_long_text_returns_multiple_chunks', function (): void {
    $service = new TextChunkingService;
    $text = str_repeat('A paragraph of text. ', 200);
    $result = $service->chunk($text, 200, 50);

    expect(count($result))->toBeGreaterThan(1);
    expect($result[0]['char_start'])->toBe(0);
    expect(end($result)['char_end'])->toBe(mb_strlen($text));
});

test('test_chunk_respects_separator_priority', function (): void {
    $service = new TextChunkingService;
    $text = "Paragraph one.\n\nParagraph two.\n\nParagraph three. ".str_repeat('word ', 100);
    $result = $service->chunk($text, 50, 0);

    expect(count($result))->toBeGreaterThan(1);
});
