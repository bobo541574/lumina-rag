<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services\Pipeline;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\ChatModule\Models\ChatSession;
use Modules\DocumentModule\Models\Document;

class ResponseBuilder
{
    private SessionManager $sessionManager;

    public function __construct(SessionManager $sessionManager)
    {
        $this->sessionManager = $sessionManager;
    }

    public function buildRefusalResponse(ChatSession $session, string $question = '', array $filters = []): array
    {
        $isBurmese = preg_match('/[\x{1000}-\x{109F}]/u', $question) === 1;

        // When the question had date filters, try to find the nearest
        // available date for a helpful hint.
        $hint = '';
        $from = $filters['report_date_from'] ?? null;
        $to = $filters['report_date_to'] ?? null;
        if ($from !== null || $to !== null) {
            $target = $to ?? $from;
            $query = Document::query()->whereNotNull('report_date');
            if (! empty($filters['user_ids'])) {
                $query->whereIn('user_id', (array) $filters['user_ids']);
            }
            if (! empty($filters['project'])) {
                $query->where('project', $filters['project']);
            }
            $nearest = $query->orderByRaw('ABS(report_date - CAST(? AS DATE))', [$target])->value('report_date');
            $nearestStr = $nearest instanceof Carbon
                ? $nearest->toDateString()
                : (string) $nearest;

            if ($nearest !== null) {
                // Check if the document actually has vectors
                $targetDoc = (clone $query)->where('report_date', $nearest)->first();
                $hasVectors = false;
                if ($targetDoc) {
                    $hasVectors = DB::table('vector_embeddings')
                        ->whereIn('chunk_id', function ($q) use ($targetDoc) {
                            $q->select('id')->from('document_chunks')->where('document_id', $targetDoc->id);
                        })->exists();
                }

                if ($nearestStr === ($from ?? $to)) {
                    if (! $hasVectors) {
                        if ($isBurmese) {
                            $hint = "\n\n{$nearestStr} ရက်စွဲအတွက် အစီရင်ခံစာရှိသော်လည်း ရှာဖွေမှုအတွက် အဆင်သင့်မဖြစ်သေးပါ။ (Re-embed ပြုလုပ်ရန် လိုအပ်နိုင်ပါသည်)";
                        } else {
                            $hint = "\n\nA report for {$nearestStr} exists but is not ready for search. (Re-embedding may be required)";
                        }
                    } else {
                        // Exact date found but no chunks above threshold.
                        // Likely a model mismatch or low similarity.
                        if ($isBurmese) {
                            $hint = "\n\n{$nearestStr} ရက်စွဲအတွက် အစီရင်ခံစာရှိသော်လည်း ရှာဖွေမှုရလဒ်တွင် မတွေ့ပါ။ အချက်အလက်များ မပြည့်စုံခြင်း သို့မဟုတ် ရှာဖွေမှုစံနှုန်းနှင့် မကိုက်ညီခြင်းကြောင့် ဖြစ်နိုင်ပါသည်။";
                        } else {
                            $hint = "\n\nA report for {$nearestStr} exists but was not found in search results. This may be due to incomplete indexing or low similarity.";
                        }
                    }
                } else {
                    if ($isBurmese) {
                        $hint = "\n\nအနီးစပ်ဆုံးရှိသော အစီရင်ခံစာများမှာ {$nearestStr} ရက်စွဲတွင် ရှိပါသည်။";
                        if (! $hasVectors) {
                            $hint .= ' (သို့သော် ရှာဖွေမှုအတွက် အဆင်သင့်မဖြစ်သေးပါ)';
                        }
                    } else {
                        $hint = "\n\nThe closest available reports are dated {$nearestStr}.";
                        if (! $hasVectors) {
                            $hint .= ' (But it is not yet ready for search)';
                        }
                    }
                }
            }
        }

        $content = $isBurmese
            ? 'မေးထားသော မေးခွန်းအတွက် အဖြေရှာမတွေ့ပါ။'.$hint
            : 'I cannot answer this question based on the available documents.'.$hint;

        $message = $this->sessionManager->saveAssistantMessage($session, $content, []);

        return [
            'session_id' => $session->id,
            'message' => [
                'id' => $message->id,
                'role' => 'assistant',
                'content' => $content,
                'sources' => [],
                'created_at' => $message->created_at->toIso8601String(),
            ],
        ];
    }

    public function buildSystemPrompt(string $confidence, bool $hasOldDocuments = false): string
    {
        $prompt = 'You are a precise document-answering assistant. Follow these rules strictly:

--- CORE RULES ---

1. **Grounding**: Answer ONLY using the provided context below. Do NOT use prior knowledge or training data. Pretend your training data does not exist.

2. **Completeness**: If the context fully answers the question, provide a complete cite-sourced answer. If it partially answers, state what you know and clearly mark what information is missing. If it does not answer at all, say: "I cannot answer this based on the available documents." Do not guess.

3. **No Hallucination**: NEVER make up facts, names, dates, or figures that are not in the context. If uncertain, say so. Fabrication is strictly forbidden.

4. **Citation**: Cite the source document title for every factual claim. Use the format [Source: Document Title]. When multiple chunks from the same document support a claim, cite the document once.

5. **Language**: Respond in the SAME LANGUAGE as the user\'s question (Burmese/English/mixed). Match the user\'s tone — formal for professional queries, concise for direct questions.

6. **Metadata Awareness**: DOCUMENTS contain metadata headers at the top of each chunk:
   - "Report by: {author_name}" — Use for WHO questions.
   - "Project: {project_name}" — Use for WHICH PROJECT questions.
   - "Date: {report_date}" — Use for WHEN questions.
   Look for these markers when the user asks about authors, projects, or dates.

7. **Structure**: Answer directly using paragraphs or bullet points as appropriate. Group related information under logical sections when answering multi-part questions.

--- BEHAVIOR ---

8. **Tone**: Be professional, concise, and factual. Avoid opinion, speculation, or unsolicited advice.

9. **Formatting**: Use Markdown for readability — **bold** for key terms, `code` for technical terms, and bullet lists for enumerations. Do not use emojis unless the user used them first.

10. **Conciseness**: Prefer short, direct answers. Do not repeat the question back. Do not add disclaimers beyond what the rules require.';

        if ($confidence === 'low') {
            $prompt .= "\n\n--- LOW CONFIDENCE ---\n\n11. The available information is limited (fewer than 3 relevant chunks). Acknowledge uncertainty clearly. Start with: \"Based on limited available information...\" and suggest what additional information or documents would help provide a better answer.";
        }

        if ($hasOldDocuments) {
            $index = $confidence === 'low' ? 12 : 11;
            $prompt .= "\n\n--- OLD DOCUMENTS ---\n\n{$index}. Some source documents are over a year old. When your answer depends on time-sensitive information from these documents, note the document date. Use: \"According to a document from [date]...\"";
        }

        return $prompt;
    }

    public function buildFilterNote(array $filters): string
    {
        $lines = [];

        if (! empty($filters['user_ids'])) {
            $names = User::whereIn('id', (array) $filters['user_ids'])->pluck('name')->toArray();
            $lines[] = '- Users: '.implode(', ', $names);
        }

        if (! empty($filters['project'])) {
            $lines[] = '- Project: '.$filters['project'];
        }

        $from = $filters['report_date_from'] ?? null;
        $to = $filters['report_date_to'] ?? null;
        if ($from !== null && $to !== null) {
            $lines[] = $from === $to
                ? '- Date: '.$from
                : "- Date range: {$from} to {$to}";
        } elseif ($from !== null) {
            $lines[] = "- From: {$from}";
        } elseif ($to !== null) {
            $lines[] = "- Until: {$to}";
        }

        if ($lines === []) {
            return '';
        }

        return "Search scope:\n".implode("\n", $lines);
    }

    public function buildSources(array $chunks): array
    {
        return array_map(fn (object $chunk): array => [
            'document_id' => $chunk->document_id,
            'document_title' => $chunk->document_title,
            'chunk_index' => $chunk->chunk_index,
            'page_number' => $chunk->page_number ?? null,
            'similarity_score' => round((float) $chunk->similarity_score, 4),
            'excerpt' => mb_substr((string) $chunk->content, 0, 200),
        ], $chunks);
    }
}
