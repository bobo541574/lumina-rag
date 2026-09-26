<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Modules\ChatModule\Services\Pipeline\FilterExtractor;

beforeEach(fn () => Carbon::setTestNow('2026-09-26 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

test('date_filters_and_resolved_prompt_agree', function (string $question, ?string $from, ?string $to, string $resolved): void {
    $extractor = new FilterExtractor(new Repository(new ArrayStore));

    $filters = $extractor->extract($question);

    expect($filters['report_date_from'] ?? null)->toBe($from);
    expect($filters['report_date_to'] ?? null)->toBe($to);
    expect($extractor->resolveTimeReferences($question))->toBe($resolved);
})->with([
    ['ဒီနေ့ report', '2026-09-26', '2026-09-26', '2026-09-26 report'],
    ['မနေ့က ဘာလုပ်လဲ', '2026-09-25', '2026-09-25', '2026-09-25က ဘာလုပ်လဲ'],
    ['ဒီတစ်ပတ်', '2026-09-21', '2026-09-27', '2026-09-21 to 2026-09-27'],
    ['ပြီးခဲ့တဲ့လ', '2026-08-01', '2026-08-31', '2026-08-01 to 2026-08-31'],
    ['last night', '2026-09-25', '2026-09-25', '2026-09-25'],
    ['this year', '2026-01-01', '2026-12-31', '2026-01-01 to 2026-12-31'],
    ['၂၀၂၆ ခုနှစ် ဧပြီလ', '2026-04-01', '2026-04-30', '၂၀၂၆ ခုနှစ် ဧပြီလ'],
    ['ဖေဖော်ဝါရီလ 2024', '2024-02-01', '2024-02-29', 'ဖေဖော်ဝါရီလ 2024'],
    ['၂၀၂၆-၀၄-၀၇', '2026-04-07', '2026-04-07', '၂၀၂၆-၀၄-၀၇'],
    ['2026-April', '2026-04-01', '2026-04-30', '2026-April'],
    ['April 15', '2026-04-15', '2026-04-15', 'April 15'],
    ['2025', '2025-01-01', '2025-12-31', '2025'],
    ['nothing here', null, null, 'nothing here'],
]);

test('last_month_does_not_overflow_at_month_end', function (): void {
    Carbon::setTestNow('2026-03-31 10:00:00');
    $extractor = new FilterExtractor(new Repository(new ArrayStore));

    expect($extractor->extract('ပြီးခဲ့တဲ့လ'))->toMatchArray([
        'report_date_from' => '2026-02-01',
        'report_date_to' => '2026-02-28',
    ]);
});
