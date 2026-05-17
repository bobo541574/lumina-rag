<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\VectorStoreModule\Services\PgvectorDriver;

function makePgvectorDriver(): PgvectorDriver
{
    return new PgvectorDriver(DB::getFacadeRoot());
}

function callApplyFiltersVector($query, array $filters, string $alias = 've'): mixed
{
    $reflection = new ReflectionMethod(PgvectorDriver::class, 'applyFiltersVector');
    $reflection->setAccessible(true);

    return $reflection->invoke(makePgvectorDriver(), $query, $filters, $alias);
}

function callApplyFiltersFts($query, array $filters): mixed
{
    $reflection = new ReflectionMethod(PgvectorDriver::class, 'applyFiltersFts');
    $reflection->setAccessible(true);

    return $reflection->invoke(makePgvectorDriver(), $query, $filters);
}

test('apply_filters_vector_adds_meta_where_clause', function (): void {
    $query = DB::table('document_chunks as dc')
        ->select('dc.id')
        ->join('documents as d', 'd.id', '=', 'dc.document_id');

    $query = callApplyFiltersVector($query, [
        'meta' => ['project' => 'Orion'],
    ], 've');

    $bindings = $query->getBindings();

    expect($bindings)->toHaveCount(1);
    expect($bindings[0])->toBe('Orion');
    expect($query->toSql())->toContain('json_extract');
    expect($query->toSql())->toContain('dc.metadata');
});

test('apply_filters_vector_adds_multiple_meta_where_clauses', function (): void {
    $query = DB::table('document_chunks as dc')
        ->select('dc.id')
        ->join('documents as d', 'd.id', '=', 'dc.document_id');

    $query = callApplyFiltersVector($query, [
        'meta' => ['project' => 'Orion', 'user_name' => 'John'],
    ], 've');

    $bindings = $query->getBindings();

    expect($bindings)->toHaveCount(2);
    expect($bindings[0])->toBe('Orion');
    expect($bindings[1])->toBe('John');
});

test('apply_filters_vector_ignores_empty_meta', function (): void {
    $query = DB::table('document_chunks as dc')
        ->select('dc.id')
        ->join('documents as d', 'd.id', '=', 'dc.document_id');

    $originalSql = $query->toSql();
    $query = callApplyFiltersVector($query, [], 've');

    expect($query->toSql())->toBe($originalSql);
});

test('apply_filters_fts_adds_meta_where_clause', function (): void {
    $query = DB::table('document_chunks as dc')
        ->select('dc.id')
        ->join('documents as d', 'd.id', '=', 'dc.document_id');

    $query = callApplyFiltersFts($query, [
        'meta' => ['report_date' => '2026-05-01'],
    ]);

    $bindings = $query->getBindings();

    expect($bindings)->toHaveCount(1);
    expect($bindings[0])->toBe('2026-05-01');
});

test('apply_filters_fts_adds_multiple_meta_where_clauses', function (): void {
    $query = DB::table('document_chunks as dc')
        ->select('dc.id')
        ->join('documents as d', 'd.id', '=', 'dc.document_id');

    $query = callApplyFiltersFts($query, [
        'meta' => ['project' => 'Orion', 'user_id' => '01J123'],
    ]);

    $bindings = $query->getBindings();

    expect($bindings)->toHaveCount(2);
    expect($bindings[0])->toBe('Orion');
    expect($bindings[1])->toBe('01J123');
});

test('apply_filters_fts_ignores_empty_meta', function (): void {
    $query = DB::table('document_chunks as dc')
        ->select('dc.id')
        ->join('documents as d', 'd.id', '=', 'dc.document_id');

    $originalSql = $query->toSql();
    $query = callApplyFiltersFts($query, []);

    expect($query->toSql())->toBe($originalSql);
});

test('apply_filters_vector_combines_meta_with_other_filters', function (): void {
    $query = DB::table('document_chunks as dc')
        ->select('dc.id')
        ->join('documents as d', 'd.id', '=', 'dc.document_id');

    $query = callApplyFiltersVector($query, [
        'document_ids' => ['01Jabc'],
        'meta' => ['project' => 'Orion'],
        'project' => 'Legacy',
    ], 've');

    $bindings = $query->getBindings();

    expect($bindings)->toHaveCount(3);
});

test('apply_filters_vector_uses_json_extract_on_sqlite', function (): void {
    $query = DB::table('document_chunks as dc')
        ->select('dc.id')
        ->join('documents as d', 'd.id', '=', 'dc.document_id');

    $query = callApplyFiltersVector($query, [
        'meta' => ['project' => 'Orion'],
    ], 've');

    $sql = $query->toSql();

    expect($sql)->toContain('json_extract');
    expect($sql)->toContain('dc.metadata');
    expect($sql)->toContain('project');
});
