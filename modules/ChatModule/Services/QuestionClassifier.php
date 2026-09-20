<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services;

/**
 * Question Classifier
 *
 * Lightweight, rule-based classification of user questions to inform adaptive
 * retrieval routing. Avoids an extra LLM call by using lexical patterns.
 *
 * Produces a type tag and a list of retrieval strategies to prioritise:
 * - comparative: asks to compare/contrast → hybrid search with multi-query recall
 * - analytical: asks for reasoning/trends → hybrid search (best recall)
 * - factual: asks for a specific fact/value → keyword/FTS-heavy search
 * - generic: everything else → default hybrid behaviour
 *
 * The keyword search ("keyword_strategy") currently maps to boosting the FTS
 * branch via the boolean query path; a pure strategy flag is emitted so
 * downstream routing can tune parameters without engine swaps.
 */
class QuestionClassifier
{
    public const TYPE_FACTUAL = 'factual';

    public const TYPE_ANALYTICAL = 'analytical';

    public const TYPE_COMPARATIVE = 'comparative';

    public const TYPE_GENERIC = 'generic';

    /**
     * Classify a question and return its type tag plus routing hints.
     *
     * @param  string  $question  Normalised user question. Example: "Compare Q3 and Q4 revenue"
     * @return array{type: string, recall_boost: bool, keyword_boost: bool} Classification result. Example: ["type" => "comparative", "recall_boost" => true, "keyword_boost" => false]
     */
    public function classify(string $question): array
    {
        $q = mb_strtolower($question);

        $comparativeKeywords = [
            'compare', 'comparison', 'versus', 'vs', 'difference', 'better', 'worse',
            'ကွဲပြား', 'ယှဉ်', 'နှိုင်း', 'ဘယ်ဟာက', 'ဘယ်ဟာပို', 'differences',
        ];
        if ($this->containsAny($q, $comparativeKeywords)) {
            return ['type' => self::TYPE_COMPARATIVE, 'recall_boost' => true, 'keyword_boost' => false];
        }

        $analyticalKeywords = [
            'trend', 'pattern', 'analy', 'explain', 'why', 'reason', 'relationship', 'correlat',
            'ပြောင်းလဲ', 'ခေတ်ရေစီးကြောင်း', 'ဘာကြောင့်', 'ရှင်းပြ', 'ဆက်စပ်', 'ခွဲခြမ်း',
        ];
        if ($this->containsAny($q, $analyticalKeywords)) {
            return ['type' => self::TYPE_ANALYTICAL, 'recall_boost' => true, 'keyword_boost' => false];
        }

        $factualKeywords = [
            'who', 'what', 'when', 'where', 'how much', 'how many', 'amount', 'total', 'number',
            'ဘယ်သူ', 'ဘယ်', 'ဘယ်နှစ်', 'ဘယ်လောက်', 'ဘယ်အချိန်', 'ဘယ်နေ့', 'ဘယ်မှာ',
            'ပမာဏ', 'စုစုပေါင်း',
        ];
        if ($this->containsAny($q, $factualKeywords)) {
            return ['type' => self::TYPE_FACTUAL, 'recall_boost' => false, 'keyword_boost' => true];
        }

        return ['type' => self::TYPE_GENERIC, 'recall_boost' => false, 'keyword_boost' => false];
    }

    /**
     * Check whether any of the given needles occurs in the haystack.
     *
     * @param  string  $haystack  Lowercased input. Example: "compare q3 and q4"
     * @param  array  $needles  Substrings to look for. Example: ["compare", "versus"]
     * @return bool True if any needle is found. Example: true
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (mb_strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
