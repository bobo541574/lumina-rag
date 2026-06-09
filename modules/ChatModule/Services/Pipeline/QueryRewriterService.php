<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services\Pipeline;

use Modules\SettingsModule\Contracts\TermAliasServiceInterface;

/**
 * Adaptive Query Rewriter Service
 *
 * Rewrites user queries before embedding and vector/FTS search.
 * Scores query complexity using explicit heuristics (word count, negation,
 * logical operators, ambiguous terms), then applies either a simple
 * rule-based rewrite or a complex rewrite path.
 *
 * Simple path: spelling correction, date expansion, synonym expansion,
 * boolean FTS query generation with AND/OR/NOT.
 *
 * Complex path: same steps + relies on the caller to trigger LLM-based
 * reformulation (expandQuery) when the score exceeds the threshold.
 *
 * @param  FilterExtractor  $filterExtractor  For resolveTimeReferences (date expansion).
 *                                            Example: mock(FilterExtractor::class)
 * @param  TermAliasServiceInterface  $termAliasService  For alias-aware synonym expansion.
 *                                                       Example: mock(TermAliasServiceInterface::class)
 * @param  int  $complexityThreshold  Score threshold for complex mode (default: 5).
 *                                    Example: 5
 */
class QueryRewriterService
{
    /**
     * Burmese spelling corrections map.
     */
    private const SPELLING_MAP = [
        'ရသဥတု' => 'ရာသီဥတု',
        '္မန်မာ' => 'မြန်မာ',
        'ကွန်ပျုတာ' => 'ကွန်ပျူတာ',
        'နည်းပညာာ' => 'နည်းပညာ',
        'ရုက္ခဗေဒိ' => 'ရုက္ခဗေဒ',
        'စီးပွားရေး' => 'စီးပွားရေး',
        'နိုင်ငံရေး' => 'နိုင်ငံရေး',
    ];

    /**
     * Static synonym map for common Burmese terms.
     * Used alongside TermAliasService aliases.
     */
    private const SYNONYM_MAP = [
        'ရာသီဥတု' => ['ရာသီဥတု', 'မိုးလေဝသ'],
        'မြန်မာ' => ['မြန်မာ', 'မြန်မာနိုင်ငံ'],
        'ကား' => ['ကား', 'ယာဉ်', 'မော်တော်'],
        'လေယာဉ်' => ['လေယာဉ်', 'လေကြောင်း'],
        'ဆရာဝန်' => ['ဆရာဝန်', 'ဒေါက်တာ'],
        'ကျောင်း' => ['ကျောင်း', 'ပညာရေး'],
    ];

    private FilterExtractor $filterExtractor;

    private TermAliasServiceInterface $termAliasService;

    private int $complexityThreshold;

    /**
     * @param  FilterExtractor  $filterExtractor  Date expansion. Example: mock(FilterExtractor::class)
     * @param  TermAliasServiceInterface  $termAliasService  Synonym expansion. Example: mock(TermAliasServiceInterface::class)
     * @param  int  $complexityThreshold  Score threshold. Example: 5
     */
    public function __construct(
        FilterExtractor $filterExtractor,
        TermAliasServiceInterface $termAliasService,
        int $complexityThreshold = 5,
    ) {
        $this->filterExtractor = $filterExtractor;
        $this->termAliasService = $termAliasService;
        $this->complexityThreshold = $complexityThreshold;
    }

    /**
     * Rewrite a user query
     *
     * Scores the question, applies date expansion + spelling correction,
     * builds an embedding text (with synonym expansion) and a boolean
     * FTS query. The mode flag tells the caller whether to trigger
     * additional LLM reformulation.
     *
     * @param  string  $question  Raw user question. Example: "မနေ့က ရာသီဥတု"
     * @return RewrittenQuery Rewritten query with embeddingText, ftsQuery, mode, and score.
     *                        Example: new RewrittenQuery("2026-05-19 ရာသီဥတု မိုးလေဝသ", "(2026-05-19) & (ရာသီဥတု | မိုးလေဝသ)", "simple", 0)
     */
    public function rewrite(string $question): RewrittenQuery
    {
        $score = $this->calculateComplexity($question);
        $mode = $score >= $this->complexityThreshold ? 'complex' : 'simple';

        // Stage 1: Date expansion (convert relative dates to absolute)
        $expanded = $this->filterExtractor->resolveTimeReferences($question);

        // Stage 2: Spelling correction
        $expanded = $this->fixSpelling($expanded);

        // Build embedding text (plain text with synonyms appended)
        $embeddingText = $this->expandSynonyms($expanded);

        // Build boolean FTS query (AND/OR/NOT operators)
        $ftsQuery = $this->buildBooleanFtsQuery($expanded);

        return new RewrittenQuery(
            embeddingText: $embeddingText,
            ftsQuery: $ftsQuery,
            mode: $mode,
            score: $score,
        );
    }

    /**
     * Calculate query complexity score
     *
     * Scoring rules:
     * - Word count: 1-3→0, 4-6→1, 7-10→2, 11+→3
     * - Negation (မဟုတ်, မ , မရှိ): +2
     * - Logical operators (နဲ့, သို့, ဒါပေမယ့်): +1 each
     * - Ambiguous terms (အဲဒါ, ဒါ, ဟို, ဘယ်လို, ဘာလဲ, ဘယ်): +2
     *
     * @param  string  $question  Raw question text. Example: "မနေ့က ရာသီဥတု"
     * @return int Complexity score (0+). Example: 0
     */
    public function calculateComplexity(string $question): int
    {
        $score = 0;

        // 1. Word count
        $words = preg_split('/\s+/', trim($question));
        $wordCount = count($words);
        if ($wordCount <= 3) {
            $score += 0;
        } elseif ($wordCount <= 6) {
            $score += 1;
        } elseif ($wordCount <= 10) {
            $score += 2;
        } else {
            $score += 3;
        }

        // 2. Negation
        if (preg_match('/\b(မဟုတ်|မ[\x{1000}-\x{109F}]|မရှိ)\b/u', $question)) {
            $score += 2;
        }

        // 3. Logical operators
        $logicalCount = preg_match_all('/\b(နဲ့|သို့|ဒါပေမယ့်)\b/u', $question);
        $score += $logicalCount;

        // 4. Ambiguous terms
        if (preg_match('/\b(အဲဒါ|ဒါ|ဟို|အဲဒီ|အဲဒီအကြောင်း|ဘယ်လို|ဘာလဲ|ဘယ်နှစ်|ဘယ်သူ|ဘယ်)\b/u', $question)) {
            $score += 2;
        }

        return $score;
    }

    /**
     * Apply spelling corrections
     *
     * Uses a static map of common Burmese misspellings.
     *
     * @param  string  $text  Input text with possible misspellings. Example: "ရသဥတု"
     * @return string Corrected text. Example: "ရာသီဥတု"
     */
    private function fixSpelling(string $text): string
    {
        return str_replace(
            array_keys(self::SPELLING_MAP),
            array_values(self::SPELLING_MAP),
            $text
        );
    }

    /**
     * Expand synonyms in text for embedding
     *
     * Appends synonyms from both the static synonym map and the
     * TermAliasService alias map. For example "ရာသီဥတု" becomes
     * "ရာသီဥတု မိုးလေဝသ" (embedding text gets both terms,
     * improving vector search recall).
     *
     * @param  string  $text  Input text. Example: "ရာသီဥတု"
     * @return string Text with synonyms appended. Example: "ရာသီဥတု မိုးလေဝသ"
     */
    private function expandSynonyms(string $text): string
    {
        $append = [];

        // Static synonym map
        foreach (self::SYNONYM_MAP as $term => $synonyms) {
            if (mb_stripos($text, $term) !== false) {
                foreach ($synonyms as $syn) {
                    if ($syn !== $term && mb_stripos($text, $syn) === false) {
                        $append[] = $syn;
                    }
                }
            }
        }

        // TermAliasService aliases
        try {
            $aliasMap = $this->termAliasService->getAliasMap();
            foreach ($aliasMap as $alias => $canonical) {
                if (mb_stripos($text, $alias) !== false && mb_stripos($text, $canonical) === false) {
                    $append[] = $canonical;
                }
            }
        } catch (\Throwable) {
            // Skip alias expansion on failure
        }

        if ($append === []) {
            return $text;
        }

        return $text.' '.implode(' ', array_unique($append));
    }

    /**
     * Build a boolean FTS query for PostgreSQL to_tsquery
     *
     * Converts the text to a boolean expression with AND (&) between terms
     * and OR (|) for known synonyms. Terms containing non-ASCII characters
     * are kept as-is (FtsQueryBuilder will strip them later). Only ASCII
     * terms get the & operator treatment.
     *
     * Handles negation: if text contains "မဟုတ်", the preceding term becomes
     * a NOT (!) operand.
     *
     * @param  string  $text  Date-expanded, spelling-corrected text.
     *                        Example: "2026-05-19 ရာသီဥတု ရန်ကုန်"
     * @return string|null Boolean FTS query or null if empty.
     *                     Example: "2026-05-19 & (ရာသီဥတု OR မိုးလေဝသ) & ရန်ကုန်"
     */
    private function buildBooleanFtsQuery(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $tokens = preg_split('/\s+/', $text);
        $terms = [];
        $skipNext = false;

        foreach ($tokens as $i => $token) {
            if ($skipNext) {
                $skipNext = false;

                continue;
            }

            // Handle negation: if next token is "မဟုတ်" or current is "မဟုတ်တဲ့"
            if (preg_match('/^မဟုတ်/u', $token)) {
                if ($terms !== []) {
                    $terms[count($terms) - 1] = '!'.ltrim($terms[count($terms) - 1], '!');
                }

                continue;
            }

            // Skip pure stopwords for FTS
            if ($this->isFtsStopword($token)) {
                continue;
            }

            // For known synonyms, build OR group (| for PostgreSQL to_tsquery)
            $synonyms = $this->getSynonymsForFts($token);
            if ($synonyms !== null) {
                $terms[] = '('.implode(' | ', $synonyms).')';
            } else {
                $terms[] = $token;
            }
        }

        if ($terms === []) {
            return null;
        }

        return implode(' & ', $terms);
    }

    /**
     * Check if a token is an FTS stopword
     *
     * @param  string  $token  Single word token. Example: "တယ်"
     * @return bool True if token should be excluded from FTS. Example: true
     */
    private function isFtsStopword(string $token): bool
    {
        static $stopwords = [
            'တယ်', 'သည်', 'ကို', 'မှာ', 'မှ', 'တွင်', 'အတွက်', 'အား',
            'ဖြင့်', 'နှင့်', 'အကြောင်း', 'ကြောင်း', 'လား', 'ဟုတ်',
            'မဟုတ်', 'ဖူး', 'ဘူး', 'ရှိ', 'ပါ', 'လဲ',
            'ဒီ', 'အဲဒီ', 'အဲဒါ', 'ဒါ', 'ဟို',
        ];

        return in_array($token, $stopwords, true);
    }

    /**
     * Get FTS synonyms for a token
     *
     * Checks both the static map and TermAliasService. Only returns
     * results when the token has known synonyms.
     *
     * @param  string  $token  Single word token. Example: "ရာသီဥတု"
     * @return array|null Array of synonym strings, or null. Example: ["ရာသီဥတု", "မိုးလေဝသ"]
     */
    private function getSynonymsForFts(string $token): ?array
    {
        // Check static map
        if (isset(self::SYNONYM_MAP[$token])) {
            return self::SYNONYM_MAP[$token];
        }

        // Check TermAliasService
        try {
            $aliasMap = $this->termAliasService->getAliasMap();
            $lower = mb_strtolower($token);
            if (isset($aliasMap[$lower]) && $aliasMap[$lower] !== $token) {
                return [$token, $aliasMap[$lower]];
            }
        } catch (\Throwable) {
            // Skip
        }

        return null;
    }
}
