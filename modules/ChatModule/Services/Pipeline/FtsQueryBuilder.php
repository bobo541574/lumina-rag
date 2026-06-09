<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services\Pipeline;

use App\Models\User;

class FtsQueryBuilder
{
    public function refine(string $question, array $filters): string
    {
        static $stopwords = [
            'show', 'list', 'tell', 'give', 'find', 'does', 'have', 'has',
            'from', 'this', 'that', 'what', 'when', 'where', 'which', 'who',
            'will', 'would', 'could', 'should', 'can', 'need', 'want',
            'there', 'their', 'with', 'about', 'into', 'over', 'than',
            'then', 'also', 'just', 'very', 'been', 'were', 'been', 'being',
            'more', 'some', 'any', 'each', 'every', 'both', 'most',
            'first', 'last', 'next', 'other', 'after', 'before', 'between',
            'your', 'yours', 'ours', 'theirs', 'mine', 'his', 'hers', 'its',
            'make', 'made', 'done', 'get', 'got', 'see', 'saw', 'use', 'used',
            'like', 'good', 'well', 'way', 'know', 'take', 'think',
            'yesterday', 'today', 'tomorrow',
            // Burmese/Myanmar conversational framing words
            'ရှိ', 'ရှိလား', 'ရှိပါ', 'ရှိပါတယ်', 'ပါ', 'ပါတယ်',
            'တယ်', 'သည်', 'ကို', 'မှာ', 'မှ', 'တွင်', 'အတွက်',
            'အား', 'ဖြင့်', 'နှင့်', 'အကြောင်း', 'ကြောင်း',
            'လား', 'ဟုတ်', 'မဟုတ်', 'ဖူး', 'ဘူး',
            'ဒီနေ့', 'ယနေ့', 'မနေ့', 'မနေ့က',
            'တင်ထားတဲ့', 'တင်သွင်းတဲ့', 'တင်ပြထားတဲ့',
            'ရေးသားတဲ့', 'ရေးထားတဲ့', 'ပြုလုပ်တဲ့',
            'ဆိုင်ရာ', 'ပတ်သက်', 'ပေးပါ', 'ပေးပါဦး',
            'ဆိုရင်', 'ဆိုရင်လည်း', 'ဆိုတာ', 'ဆိုတာလဲ',
            'ဘယ်', 'ဘယ်လို', 'ဘယ်ရက်', 'ဘယ်အချိန်',
            'ရှိတယ်ဆို', 'ရှိသလား', 'ရှိခဲ့လား', 'ရှိနေလား',
            'ဘာတွေ', 'ဘာလဲ', 'လဲ', 'လဲဆိုတာ',
            'အဲ့ဒါဆို', 'ဒါဆို', 'အဲ့ဒီ', 'အဲဒီ', 'ရှိတဲ့', 'ဆို', 'ရက်',
        ];

        // Strip matched user names
        if (isset($filters['user_ids'])) {
            try {
                $users = User::whereIn('id', $filters['user_ids'])->pluck('name');
                foreach ($users as $name) {
                    // Use a more robust pattern for Burmese names (not relying on \b)
                    $question = preg_replace('/'.preg_quote($name, '/').'/iu', '', $question);
                    // Also try stripping just the base name before English parenthetical
                    $baseName = preg_replace('/\s*\([^)]*\)$/u', '', $name);
                    if ($baseName !== $name && $baseName !== '') {
                        $question = preg_replace('/'.preg_quote($baseName, '/').'/iu', '', $question);
                    }
                }
            } catch (\Throwable) {
                // skip
            }
        }

        // Strip matched project name
        if (isset($filters['project'])) {
            $question = preg_replace('/'.preg_quote($filters['project'], '/').'/iu', '', $question);
        }

        // Strip date patterns (YYYY-MM-DD or YYYY-MM) before individual
        // year components to avoid leaving bare "-MM" tokens that PostgreSQL
        // plainto_tsquery would interpret as a negation operator.
        $question = preg_replace('/\b\d{4}[-\.\/]\d{1,2}([-\.\/]\d{1,2})?\b/', '', $question);

        // Strip YYYY-MonthName patterns (e.g. "2026-April") before individual
        // year/month stripping so they don't leave a bare "-" token.
        $question = preg_replace('/\b\d{4}[-\.\/](January|February|March|April|May|June|July|August|September|October|November|December)\b/i', '', $question);

        // Strip MonthName DD patterns (e.g. "April 15") before individual
        // month stripping so they don't leave a bare day number.
        $question = preg_replace('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2}\b/i', '', $question);

        // Strip year and quarter patterns
        $question = preg_replace('/\b\d{4}\b/', '', $question);
        $question = preg_replace('/\bQ[1-4]\b/i', '', $question);

        // Strip month names
        $question = preg_replace('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\b/i', '', $question);

        // Strip common stopwords
        foreach ($stopwords as $word) {
            $quoted = preg_quote($word, '/');
            // If it's a Burmese word (contains Burmese characters), don't use \b
            if (preg_match('/[\x{1000}-\x{109F}]/u', $word)) {
                $question = preg_replace('/'.$quoted.'/iu', '', $question);
            } else {
                $question = preg_replace('/\b'.$quoted.'\b/iu', '', $question);
            }
        }

        // Keep meaningful content words:
        // - ASCII words: must be > 2 chars, not just punctuation
        // - Non-ASCII words (Burmese, etc.): must be > 1 char, not in
        //   stopwords list. The pg 'english' FTS parser keeps non-ASCII
        //   tokens as-is so they can match document content.
        $words = preg_split('/\s+/', $question);
        $words = array_filter($words, function (string $w) use ($stopwords): bool {
            $w = trim($w);
            if ($w === '') {
                return false;
            }

            // Check if it's a stopword (for cases where preg_replace missed it)
            if (in_array(mb_strtolower($w), $stopwords)) {
                return false;
            }

            if (preg_match('/[a-zA-Z0-9]/', $w)) {
                return mb_strlen($w) > 2;
            }

            return mb_strlen($w) > 1;
        });
        $words = array_map(fn (string $w): string => preg_replace('/[^a-zA-Z0-9\p{L}\-\.]/u', '', $w), $words);
        $words = array_filter($words, fn (string $w): bool => $w !== '');
        // Clean up tokens: strip leading/trailing hyphens, and remove
        // tokens that are purely hyphens or hyphen-prefixed numbers
        // (leftover date fragments like "-04" that FTS would treat as NOT)
        $words = array_filter($words, fn (string $w): bool => ! preg_match('/^-+\d*$/', $w));
        $words = array_map(fn (string $w): string => trim($w, '-'), $words);
        $words = array_filter($words, fn (string $w): bool => $w !== '');
        $words = array_udiff($words, $stopwords, fn (string $a, string $b): int => mb_strtolower($a) <=> mb_strtolower($b));
        // Keep only ASCII tokens for the English FTS parser. Burmese and
        // other non-ASCII tokens produce fragments from stopword removal
        // that don't match the tsvector (which stores contiguous Burmese
        // text as single tokens). Rely on the vector search for non-English
        // queries.
        $words = array_filter($words, fn (string $w): bool => ! preg_match('/[^\x00-\x7F]/', $w));

        $result = implode(' ', array_unique($words));
        $result = trim(preg_replace('/\s+/', ' ', $result));

        if ($result === '') {
            // Fallback: extract only ASCII content words from the original
            // question. Skip non-ASCII text — the English FTS parser cannot
            // handle Burmese/Unicode tokens and AND-matching fragments would
            // never match the tsvector.
            $fallback = preg_replace('/[^\x00-\x7F]/u', '', $question);
            $fallback = preg_replace('/\b\d{4}[-\.\/]\d{1,2}([-\.\/]\d{1,2})?\b/', '', $fallback, -1);
            $fallback = preg_replace('/\b\d{4}\b/', '', $fallback, -1);
            $fallback = preg_replace('/-+/', ' ', $fallback, -1);
            $fallback = trim(preg_replace('/\s+/', ' ', $fallback, -1));
            $fallback = implode(' ', array_filter(explode(' ', $fallback), fn (string $w): bool => mb_strlen($w) > 2));

            return $fallback !== '' ? $fallback : 'report';
        }

        return $result;
    }
}
