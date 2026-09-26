<?php

declare(strict_types=1);

use Modules\ChatModule\Services\Pipeline\FtsQueryBuilder;

test('constrain_boolean_drops_terms_refine_removed', function (?string $boolean, string $refined, ?string $expected): void {
    expect((new FtsQueryBuilder)->constrainBoolean($boolean, $refined))->toBe($expected);
})->with([
    'stopwords and names dropped' => ['show & Aung & Zeya & revenue & report', 'revenue report', 'revenue & report'],
    'synonym group kept' => ['(revenue | income) & show', 'revenue', '(revenue | income)'],
    'negation kept' => ['revenue & !Orion', 'revenue Orion', 'revenue & !Orion'],
    'punctuation tolerated' => ['revenue?', 'revenue', 'revenue?'],
    'nothing survives' => ['show & list', '', null],
    'no boolean query' => [null, 'revenue', null],
]);

test('refine_strips_dates_quarters_and_stopwords', function (): void {
    expect((new FtsQueryBuilder)->refine('Show the Q3 2026 revenue report', []))->toBe('the revenue report');
});
