<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\ChatModule\Services\RAGPipelineService;
use Modules\DocumentModule\Models\Document;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

$question = 'အောင်ဇေယျာ Project Orion report 2026-04 လပိုင်းအတွက်ရှိလား? ရှိတယ်ဆို အသေးစိတ်ရှင်းပြပေးပါ။';
$rag = app(RAGPipelineService::class);

// 1. Check Filters
$reflection = new ReflectionClass($rag);
$method = $reflection->getMethod('extractFiltersFromQuestion');
$method->setAccessible(true);

// Clear cache to be sure
Cache::forget('rag:users');
Cache::forget('rag:projects');

// Debug: What's in the DB?
$users = User::select('id', 'name')->get();
echo 'Users in DB: '.$users->count()."\n";
foreach ($users as $u) {
    if (str_contains($u->name, 'အောင်ဇေယျာ')) {
        echo 'Found target user: '.$u->id.' | '.$u->name."\n";
        $baseName = preg_replace('/\s*\([^)]*\)$/u', '', $u->name);
        echo "Base name: '$baseName' | Match: ".(preg_match('/'.preg_quote($baseName, '/').'/iu', $question) ? 'YES' : 'NO')."\n";
    }
}

$projects = Document::distinct()->whereNotNull('project')->where('project', '!=', '')->pluck('project');
echo 'Projects in DB: '.$projects->count()."\n";
foreach ($projects as $p) {
    if (str_contains($p, 'Orion')) {
        echo "Found target project: '$p' | Match: ".(preg_match('/'.preg_quote($p, '/').'/iu', $question) ? 'YES' : 'NO')."\n";
    }
}

$filters = $method->invoke($rag, $question);

echo "Filters extracted:\n";
print_r($filters);

// 2. Check Document
$docQuery = Document::query();
if (! empty($filters['user_ids'])) {
    $docQuery->whereIn('user_id', $filters['user_ids']);
}
if (! empty($filters['project'])) {
    $docQuery->where('project', $filters['project']);
}
if (! empty($filters['report_date_from'])) {
    $docQuery->where('report_date', '>=', $filters['report_date_from']);
}
if (! empty($filters['report_date_to'])) {
    $docQuery->where('report_date', '<=', $filters['report_date_to']);
}

$doc = $docQuery->first();
if (! $doc) {
    echo "No document found with these filters.\n";
    exit;
}

echo 'Document found: '.$doc->id.' | '.$doc->title.' | Model: '.$doc->embedding_model.' | Model ID: '.$doc->embedding_model_id."\n";

// 3. Check Vectors
$chunks = DB::table('document_chunks')->where('document_id', $doc->id)->get();
echo 'Chunks count for this document: '.$chunks->count()."\n";

if ($chunks->count() > 0) {
    $chunkIds = $chunks->pluck('id');
    $veCount = DB::table('vector_embeddings')->whereIn('chunk_id', $chunkIds)->count();
    echo "Vector count in main table: $veCount\n";

    $dim = 768; // Based on nomic-embed-text
    $shardCount = DB::table('ve_'.$dim)->whereIn('chunk_id', $chunkIds)->count();
    echo "Vector count in ve_$dim shard: $shardCount\n";
}

// 4. Try Search Simulation
$embedder = app(EmbeddingServiceInterface::class);
$vectorStore = app(VectorStoreInterface::class);

$qVector = $embedder->embed($question, $doc->embedding_model);
$searchFilters = $filters;
$searchFilters['similarity_threshold'] = 0.20;
$searchFilters['model_name'] = $doc->embedding_model;

echo 'Running search simulation with model_name: '.$searchFilters['model_name']."\n";
$results = $vectorStore->search($qVector, 5, $searchFilters);

echo 'Search results count: '.count($results)."\n";
foreach ($results as $r) {
    echo '- Chunk: '.$r->chunk_id.' | Score: '.$r->similarity_score.' | Doc: '.$r->document_title."\n";
}
