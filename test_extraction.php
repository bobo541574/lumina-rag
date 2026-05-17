<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Modules\ChatModule\Services\RAGPipelineService;

$question = 'အောင်ဇေယျာ Project Orion report 2026-04 လပိုင်းအတွက်ရှိလား? ရှိတယ်ဆို အသေးစိတ်ရှင်းပြပေးပါ။';
$rag = app(RAGPipelineService::class);

$reflection = new ReflectionClass($rag);
$method = $reflection->getMethod('extractFiltersFromQuestion');
$method->setAccessible(true);
$filters = $method->invoke($rag, $question);

echo "Question: $question\n";
echo "Filters extracted:\n";
print_r($filters);
