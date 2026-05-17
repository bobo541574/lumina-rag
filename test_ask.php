<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Modules\ChatModule\Services\RAGPipelineService;

$question = 'အောင်ဇေယျာ Project Orion report 2026-04 လပိုင်းအတွက်ရှိလား? ရှိတယ်ဆို အသေးစိတ်ရှင်းပြပေးပါ။';
$rag = app(RAGPipelineService::class);

$result = $rag->ask($question);

echo "Question: $question\n";
echo 'Answer content: '.$result['message']['content']."\n";
echo 'Sources: '.count($result['message']['sources'])."\n";
foreach ($result['message']['sources'] as $s) {
    echo '- '.$s['document_title'].' (Score: '.$s['similarity_score'].")\n";
}
