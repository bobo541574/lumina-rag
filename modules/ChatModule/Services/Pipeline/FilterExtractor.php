<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services\Pipeline;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;
use Modules\DocumentModule\Models\Document;

class FilterExtractor
{
    /** @var array<array{0: string, 1: string}> Relative time phrases (Myanmar + English) → period, checked in order */
    private const TIME_PERIODS = [
        ['/ဒီနေ့/u', 'today'],
        ['/ယနေ့/u', 'today'],
        ['/မနေ့/u', 'yesterday'],
        ['/ဒီတစ်ပတ်/u', 'this_week'],
        ['/ဒီတစ်လ/u', 'this_month'],
        ['/ဒီလ/u', 'this_month'],
        ['/ဒီတစ်နှစ်/u', 'this_year'],
        // ပြီးခဲ့တဲ့ = last/past
        ['/ပြီးခဲ့တဲ့\s*အပတ်/u', 'last_week'],
        ['/ပြီးခဲ့တဲ့\s*လ/u', 'last_month'],
        ['/ပြီးခဲ့တဲ့\s*နှစ်/u', 'last_year'],
        ['/\bthis\s*day\b/i', 'today'],
        ['/\btoday\b/i', 'today'],
        ['/\byesterday\b/i', 'yesterday'],
        ['/\blast\s*night\b/i', 'yesterday'],
        ['/\bthis\s*week\b/i', 'this_week'],
        ['/\bthis\s*month\b/i', 'this_month'],
        ['/\bthis\s*year\b/i', 'this_year'],
    ];

    /** @var array<string, int> English month name → number */
    public const MONTHS = [
        'January' => 1, 'February' => 2, 'March' => 3, 'April' => 4, 'May' => 5, 'June' => 6,
        'July' => 7, 'August' => 8, 'September' => 9, 'October' => 10, 'November' => 11, 'December' => 12,
    ];

    /** @var array<string, int> Burmese month name → number */
    private const BURMESE_MONTHS = [
        'ဇန်နဝါရီ' => 1, 'ဖေဖော်ဝါရီ' => 2, 'မတ်' => 3, 'ဧပြီ' => 4,
        'မေ' => 5, 'ဇွန်' => 6, 'ဇူလိုင်' => 7, 'ဩဂုတ်' => 8,
        'စက်တင်ဘာ' => 9, 'အောက်တိုဘာ' => 10, 'နိုဝင်ဘာ' => 11, 'ဒီဇင်ဘာ' => 12,
    ];

    /** @var array<string, string> Burmese digit → ASCII digit */
    public const BURMESE_DIGITS = ['၀' => '0', '၁' => '1', '၂' => '2', '၃' => '3', '၄' => '4', '၅' => '5', '၆' => '6', '၇' => '7', '၈' => '8', '၉' => '9'];

    private CacheRepository $cache;

    public function __construct(CacheRepository $cache)
    {
        $this->cache = $cache;
    }

    public function extract(string $question): array
    {
        $filters = [];

        // ── User name extraction ──────────────────────────────────────────
        // Look up user names from the DB; match against the question
        try {
            $users = $this->cache->remember('rag:users', 300, fn (): Collection => User::select('id', 'name')->get());
            $matchedUserIds = [];
            foreach ($users as $user) {
                $cleanName = preg_quote($user->name, '/');
                if (preg_match('/'.$cleanName.'/iu', $question)) {
                    $matchedUserIds[] = $user->id;

                    continue;
                }
                // Try matching just the base name before any English parenthetical
                $baseName = preg_replace('/\s*\([^)]*\)$/u', '', $user->name);
                if ($baseName !== $user->name && preg_match('/'.preg_quote($baseName, '/').'/iu', $question)) {
                    $matchedUserIds[] = $user->id;
                }
            }
            if ($matchedUserIds !== []) {
                $filters['user_ids'] = $matchedUserIds;
            }
        } catch (\Throwable) {
            // DB not available — skip
        }

        // ── Project name extraction ───────────────────────────────────────
        try {
            $projects = $this->cache->remember('rag:projects', 300, fn (): Collection => Document::distinct()->whereNotNull('project')->where('project', '!=', '')->pluck('project'));

            // Sort projects by length descending to match the most specific project name first
            $sortedProjects = $projects->sortByDesc(fn ($p) => mb_strlen($p));

            foreach ($sortedProjects as $project) {
                $clean = preg_quote($project, '/');
                if (preg_match('/'.$clean.'/iu', $question)) {
                    $filters['project'] = $project;
                    break;
                }
            }
        } catch (\Throwable) {
            // DB not available — skip
        }

        // ── Time period extraction (Myanmar + English) ────────────────────
        $today = now()->startOfDay();
        foreach (self::TIME_PERIODS as [$pattern, $period]) {
            if (preg_match($pattern, $question)) {
                $filters = $this->applyTimePeriod($filters, $period, $today);
                break;
            }
        }

        // Burmese digits → ASCII, so "၂၀၂၆" matches the \d patterns below.
        $qDigits = strtr($question, self::BURMESE_DIGITS);

        // ── Burmese month names ───────────────────────────────────────────
        foreach (self::BURMESE_MONTHS as $name => $month) {
            $monthPtn = preg_quote($name, '/');

            // "၂၀၂၆ ခုနှစ် ဧပြီလ" or "ဧပြီလ ၂၀၂၆"; "ဧပြီလ" alone → current year
            if (preg_match('/(\d{4})\s*ခုနှစ်\s*'.$monthPtn.'လ/u', $qDigits, $m)
                || preg_match('/'.$monthPtn.'လ\s*(\d{4})/u', $qDigits, $m)) {
                $filters = $this->withRange($filters, ...$this->monthRange((int) $m[1], $month));
                break;
            }
            if (preg_match('/'.$monthPtn.'လ/u', $question)) {
                $filters = $this->withRange($filters, ...$this->monthRange(now()->year, $month));
                break;
            }
        }

        // ── Date patterns (most specific first) ──────────────────────────
        $months = implode('|', array_keys(self::MONTHS));

        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $qDigits, $m)) {
            // YYYY-MM-DD (e.g. 2026-04-07)
            $filters = $this->withRange($filters, "{$m[1]}-{$m[2]}-{$m[3]}", "{$m[1]}-{$m[2]}-{$m[3]}");
        } elseif (preg_match('/\b(\d{4})-(\d{2})\b/', $question, $m)) {
            // YYYY-MM (e.g. 2026-04)
            $filters = $this->withRange($filters, ...$this->monthRange((int) $m[1], (int) $m[2]));
        } elseif (preg_match("/\\b(\\d{4})-({$months})\\b/i", $question, $m)) {
            // YYYY-MonthName (e.g. 2026-April) — may come from normalizeQuestion()
            $filters = $this->withRange($filters, ...$this->monthRange((int) $m[1], self::MONTHS[ucfirst(strtolower($m[2]))]));
        } elseif (preg_match("/\\b({$months})\\s+(\\d{1,2})\\b/i", $question, $m)) {
            // MonthName DD (e.g. "April 15") — no year, use current year
            $date = Carbon::create(now()->year, self::MONTHS[ucfirst(strtolower($m[1]))], (int) $m[2])->toDateString();
            $filters = $this->withRange($filters, $date, $date);
        } elseif (preg_match('/\b(Q[1-4]\s*\d{4})\b/i', $question, $m)) {
            // Quarter (e.g. "Q3 2026")
            preg_match('/^Q([1-4])\s*(\d{4})$/i', $m[1], $q);
            $start = Carbon::create((int) $q[2], ((int) $q[1] - 1) * 3 + 1, 1);
            $filters = $this->withRange($filters, $start->toDateString(), $start->copy()->endOfQuarter()->toDateString());
        } elseif (! isset($filters['report_date_from']) && preg_match('/\b(\d{4})\b/', $question) && preg_match('/\b(\d{4})\b/', $qDigits, $m)) {
            // Bare year, unless a Burmese month + year already set a narrower range
            $filters = $this->withRange($filters, "{$m[1]}-01-01", "{$m[1]}-12-31");
        }

        return $filters;
    }

    public function applyTimePeriod(array $filters, string $period, Carbon $today): array
    {
        $range = $this->periodRange($period, $today);

        return $range === null ? $filters : $this->withRange($filters, $range[0]->format('Y-m-d'), $range[1]->format('Y-m-d'));
    }

    /**
     * Replace relative time phrases with the dates they stand for, so the LLM
     * answers for "2026-05-15" instead of guessing what "yesterday" means.
     *
     * Uses the same phrase table as extract(), so filters and prompt agree.
     *
     * @param  string  $question  Question text. Example: "What happened yesterday?"
     * @return string Example: "What happened 2026-09-25?"
     */
    public function resolveTimeReferences(string $question): string
    {
        $today = now()->startOfDay();

        foreach (self::TIME_PERIODS as [$pattern, $period]) {
            [$from, $to] = $this->periodRange($period, $today);
            $from = $from->format('Y-m-d');
            $to = $to->format('Y-m-d');
            $question = preg_replace($pattern, $from === $to ? $from : "{$from} to {$to}", $question);
        }

        return $question;
    }

    /**
     * Date range for a named relative period.
     *
     * @param  string  $period  One of the TIME_PERIODS values. Example: "last_week"
     * @param  Carbon  $today  Start of today. Example: now()->startOfDay()
     * @return array{0: Carbon, 1: Carbon}|null Start and end, or null for an unknown period
     */
    private function periodRange(string $period, Carbon $today): ?array
    {
        $t = $today->copy();

        return match ($period) {
            'today' => [$t, $t->copy()],
            'yesterday' => [$t->subDay(), $t->copy()],
            'this_week' => [$t->startOfWeek(Carbon::MONDAY), $t->copy()->endOfWeek(Carbon::SUNDAY)],
            'last_week' => [$t->subWeek()->startOfWeek(Carbon::MONDAY), $t->copy()->endOfWeek(Carbon::SUNDAY)],
            'this_month' => [$t->startOfMonth(), $t->copy()->endOfMonth()],
            'last_month' => [$t->subMonthNoOverflow()->startOfMonth(), $t->copy()->endOfMonth()],
            'this_year' => [$t->startOfYear(), $t->copy()->endOfYear()],
            'last_year' => [$t->subYear()->startOfYear(), $t->copy()->endOfYear()],
            default => null,
        };
    }

    /**
     * @return array{0: string, 1: string} First and last day of the month. Example: ["2026-02-01", "2026-02-28"]
     */
    private function monthRange(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1);

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }

    /**
     * @return array Filters with report_date_from/to set
     */
    private function withRange(array $filters, string $from, string $to): array
    {
        $filters['report_date_from'] = $from;
        $filters['report_date_to'] = $to;

        return $filters;
    }
}
