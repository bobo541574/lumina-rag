<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Modules\ChatModule\Services\Pipeline\FilterExtractor;

test('quarter_filters_end_on_the_real_last_day_of_the_quarter', function (string $question, string $from, string $to): void {
    $extractor = new FilterExtractor(new Repository(new ArrayStore));

    $filters = $extractor->extract($question);

    expect($filters['report_date_from'])->toBe($from);
    expect($filters['report_date_to'])->toBe($to);
})->with([
    'Q1' => ['Q1 2026 revenue', '2026-01-01', '2026-03-31'],
    'Q2' => ['Q2 2026 revenue', '2026-04-01', '2026-06-30'],
    'Q3 without space' => ['Q32026 revenue', '2026-07-01', '2026-09-30'],
    'Q4' => ['q4 2025 revenue', '2025-10-01', '2025-12-31'],
]);
