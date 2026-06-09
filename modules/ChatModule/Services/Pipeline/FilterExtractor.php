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
        $now = now();
        $today = $now->copy()->startOfDay();
        $timePeriods = [

            // Myanmar: today
            ['/ဒီနေ့/u',          'today'],
            ['/ယနေ့/u',            'today'],
            ['/မနေ့/u',            'yesterday'],
            ['/မနေ့က/u',           'yesterday'],

            // Myanmar: this week/month/year
            ['/ဒီတစ်ပတ်/u',        'this_week'],
            ['/ဒီတစ်လ/u',          'this_month'],
            ['/ဒီလ/u',              'this_month'],
            ['/ဒီတစ်နှစ်/u',       'this_year'],

            // Myanmar: last week/month/year (ပြီးခဲ့တဲ့ = last/past)
            ['/ပြီးခဲ့တဲ့\s*အပတ်/u',  'last_week'],
            ['/ပြီးခဲ့တဲ့\s*လ/u',     'last_month'],
            ['/ပြီးခဲ့တဲ့\s*နှစ်/u',  'last_year'],

            // English
            ['/\bthis\s*day\b/i',    'today'],
            ['/\btoday\b/i',         'today'],
            ['/\byesterday\b/i',     'yesterday'],
            ['/\blast\s*night\b/i',  'yesterday'],
            ['/\bthis\s*week\b/i',   'this_week'],
            ['/\bthis\s*month\b/i',  'this_month'],
            ['/\bthis\s*year\b/i',   'this_year'],
        ];

        foreach ($timePeriods as [$pattern, $period]) {
            if (preg_match($pattern, $question)) {
                $filters = $this->applyTimePeriod($filters, $period, $today);
                break;
            }
        }

        // ── Burmese month names + digit conversion ──────────────────────
        $burmeseDigitMap = ['၀' => '0', '၁' => '1', '၂' => '2', '၃' => '3', '၄' => '4', '၅' => '5', '၆' => '6', '၇' => '7', '၈' => '8', '၉' => '9'];
        $hasBurmeseDigits = strtr($question, $burmeseDigitMap) !== $question;
        // Normalized copy with digits converted (for number matching)
        $qDigits = strtr($question, $burmeseDigitMap);

        $burmeseMonths = [
            'ဇန်နဝါရီ' => 1, 'ဖေဖော်ဝါရီ' => 2, 'မတ်' => 3, 'ဧပြီ' => 4,
            'မေ' => 5, 'ဇွန်' => 6, 'ဇူလိုင်' => 7, 'ဩဂုတ်' => 8,
            'စက်တင်ဘာ' => 9, 'အောက်တိုဘာ' => 10, 'နိုဝင်ဘာ' => 11, 'ဒီဇင်ဘာ' => 12,
        ];

        foreach ($burmeseMonths as $mmName => $mmNum) {
            $monthPtn = preg_quote($mmName, '/');
            $mmPad = str_pad((string) $mmNum, 2, '0', STR_PAD_LEFT);

            // "၂၀၂၆ ခုနှစ် ဧပြီလ" or "2026 ခုနှစ် ဧပြီလ"
            if (preg_match('/(\d{4})\s*ခုနှစ်\s*'.$monthPtn.'လ/u', $qDigits, $m)) {
                $filters['report_date_from'] = "{$m[1]}-{$mmPad}-01";
                $filters['report_date_to'] = "{$m[1]}-{$mmPad}-".now()->create((int) $m[1], $mmNum)->endOfMonth()->format('d');
                break;
            }
            // "ဧပြီလ ၂၀၂၆" or "ဧပြီလ 2026"
            if (preg_match('/'.$monthPtn.'လ\s*(\d{4})/u', $qDigits, $m)) {
                $filters['report_date_from'] = "{$m[1]}-{$mmPad}-01";
                $filters['report_date_to'] = "{$m[1]}-{$mmPad}-".now()->create((int) $m[1], $mmNum)->endOfMonth()->format('d');
                break;
            }
            // "ဧပြီလ" alone → current year
            if (preg_match('/'.$monthPtn.'လ/u', $question)) {
                $year = now()->year;
                $filters['report_date_from'] = "{$year}-{$mmPad}-01";
                $filters['report_date_to'] = "{$year}-{$mmPad}-".now()->create($year, $mmNum)->endOfMonth()->format('d');
                break;
            }
        }

        // ── Date patterns (most specific first) ──────────────────────────
        // Use digit-normalized question for number patterns so Burmese
        // digits like "၂၀၂၆-၀၄" also match as "2026-04".
        $dateSrc = $hasBurmeseDigits ? $qDigits : $question;

        // YYYY-MM-DD (e.g. 2026-04-07)
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $dateSrc, $m)) {
            $filters['report_date_from'] = "{$m[1]}-{$m[2]}-{$m[3]}";
            $filters['report_date_to'] = "{$m[1]}-{$m[2]}-{$m[3]}";
        } elseif (preg_match('/\b(\d{4})-(\d{2})\b/', $question, $m)) {
            // YYYY-MM (e.g. 2026-04)
            $filters['report_date_from'] = "{$m[1]}-{$m[2]}-01";
            $filters['report_date_to'] = "{$m[1]}-{$m[2]}-".now()->create($m[1], $m[2])->endOfMonth()->format('d');
        } elseif (preg_match('/\b(\d{4})-(January|February|March|April|May|June|July|August|September|October|November|December)\b/i', $question, $m)) {
            // YYYY-MonthName (e.g. 2026-April) — may come from normalizeQuestion()
            $monthMap = [
                'January' => '01', 'February' => '02', 'March' => '03',
                'April' => '04', 'May' => '05', 'June' => '06',
                'July' => '07', 'August' => '08', 'September' => '09',
                'October' => '10', 'November' => '11', 'December' => '12',
            ];
            $monthNum = $monthMap[$m[2]];
            $filters['report_date_from'] = "{$m[1]}-{$monthNum}-01";
            $filters['report_date_to'] = "{$m[1]}-{$monthNum}-".now()->create((int) $m[1], (int) $monthNum)->endOfMonth()->format('d');
        } elseif (preg_match('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})\b/i', $question, $m)) {
            // MonthName DD (e.g. "April 15") — no year, use current year
            $monthMap = [
                'January' => '01', 'February' => '02', 'March' => '03',
                'April' => '04', 'May' => '05', 'June' => '06',
                'July' => '07', 'August' => '08', 'September' => '09',
                'October' => '10', 'November' => '11', 'December' => '12',
            ];
            $monthNum = $monthMap[$m[1]];
            $day = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $year = now()->year;
            $filters['report_date_from'] = "{$year}-{$monthNum}-{$day}";
            $filters['report_date_to'] = "{$year}-{$monthNum}-{$day}";
        } else {
            // Quarter / year / month-name patterns
            $datePatterns = [
                '/\b(Q[1-4]\s*\d{4})\b/i',
                '/\b(\d{4})\b/',
                '/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4}\b/i',
            ];

            foreach ($datePatterns as $pattern) {
                if (preg_match($pattern, $question, $matches)) {
                    $dateStr = $matches[1];
                    if (preg_match('/^Q[1-4]/i', $dateStr)) {
                        $quarter = (int) substr($dateStr, 1, 1);
                        $year = (int) trim(substr($dateStr, strpos($dateStr, ' ') + 1));
                        $filters['report_date_from'] = "{$year}-".str_pad((string) (($quarter - 1) * 3 + 1), 2, '0', STR_PAD_LEFT).'-01';
                        $filters['report_date_to'] = "{$year}-".str_pad((string) ($quarter * 3), 2, '0', STR_PAD_LEFT).'-31';
                    } elseif (preg_match('/^\d{4}$/', $dateStr)) {
                        // Use digit-normalized $dateSrc so Burmese
                        // digits like "၂၀၂၆" → "2026"
                        preg_match('/\b(\d{4})\b/', $dateSrc, $m2);
                        $year = $m2[1] ?? $dateStr;
                        $filters['report_date_from'] = "{$year}-01-01";
                        $filters['report_date_to'] = "{$year}-12-31";
                    }
                    break;
                }
            }
        }

        return $filters;
    }

    public function applyTimePeriod(array $filters, string $period, Carbon $today): array
    {
        $from = null;
        $to = null;

        switch ($period) {
            case 'today':
                $from = $today->copy();
                $to = $today->copy()->endOfDay();
                break;
            case 'yesterday':
                $from = $today->copy()->subDay();
                $to = $from->copy()->endOfDay();
                break;
            case 'this_week':
                $from = $today->copy()->startOfWeek(Carbon::MONDAY);
                $to = $from->copy()->endOfWeek(Carbon::SUNDAY);
                break;
            case 'last_week':
                $from = $today->copy()->subWeek()->startOfWeek(Carbon::MONDAY);
                $to = $from->copy()->endOfWeek(Carbon::SUNDAY);
                break;
            case 'this_month':
                $from = $today->copy()->startOfMonth();
                $to = $today->copy()->endOfMonth();
                break;
            case 'last_month':
                $from = $today->copy()->subMonth()->startOfMonth();
                $to = $from->copy()->endOfMonth();
                break;
            case 'this_year':
                $from = $today->copy()->startOfYear();
                $to = $today->copy()->endOfYear();
                break;
            case 'last_year':
                $from = $today->copy()->subYear()->startOfYear();
                $to = $from->copy()->endOfYear();
                break;
        }

        if ($from !== null) {
            $filters['report_date_from'] = $from->format('Y-m-d');
            $filters['report_date_to'] = $to->format('Y-m-d');
        }

        return $filters;
    }

    public function resolveTimeReferences(string $question): string
    {
        $now = now();
        $today = $now->copy()->startOfDay();

        $dateRanges = [

            // Myanmar: today
            ['/ဒီနေ့/u',     $today->format('Y-m-d')],
            ['/ယနေ့/u',       $today->format('Y-m-d')],

            // Myanmar: yesterday
            ['/မနေ့/u',       $today->copy()->subDay()->format('Y-m-d')],

            // Myanmar: this week/month/year
            ['/ဒီတစ်ပတ်/u',   $today->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d').' to '.$today->copy()->endOfWeek(Carbon::SUNDAY)->format('Y-m-d')],
            ['/ဒီတစ်လ/u',     $today->copy()->startOfMonth()->format('Y-m-d').' to '.$today->copy()->endOfMonth()->format('Y-m-d')],
            ['/ဒီလ/u',         $today->copy()->startOfMonth()->format('Y-m-d').' to '.$today->copy()->endOfMonth()->format('Y-m-d')],
            ['/ဒီတစ်နှစ်/u',  $today->copy()->startOfYear()->format('Y-m-d').' to '.$today->copy()->endOfYear()->format('Y-m-d')],

            // Myanmar: last week/month/year
            ['/ပြီးခဲ့တဲ့\s*အပတ်/u', $today->copy()->subWeek()->startOfWeek(Carbon::MONDAY)->format('Y-m-d').' to '.$today->copy()->subWeek()->endOfWeek(Carbon::SUNDAY)->format('Y-m-d')],
            ['/ပြီးခဲ့တဲ့\s*လ/u',    $today->copy()->subMonth()->startOfMonth()->format('Y-m-d').' to '.$today->copy()->subMonth()->endOfMonth()->format('Y-m-d')],
            ['/ပြီးခဲ့တဲ့\s*နှစ်/u', $today->copy()->subYear()->startOfYear()->format('Y-m-d').' to '.$today->copy()->subYear()->endOfYear()->format('Y-m-d')],

            // English: today / yesterday
            ['/\btoday\b/i',      $today->format('Y-m-d')],
            ['/\byesterday\b/i',  $today->copy()->subDay()->format('Y-m-d')],

            // English: this week/month/year
            ['/\bthis\s*week\b/i',  $today->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d').' to '.$today->copy()->endOfWeek(Carbon::SUNDAY)->format('Y-m-d')],
            ['/\bthis\s*month\b/i', $today->copy()->startOfMonth()->format('Y-m-d').' to '.$today->copy()->endOfMonth()->format('Y-m-d')],
            ['/\bthis\s*year\b/i',  $today->copy()->startOfYear()->format('Y-m-d').' to '.$today->copy()->endOfYear()->format('Y-m-d')],
        ];

        foreach ($dateRanges as [$pattern, $replacement]) {
            $question = preg_replace($pattern, $replacement, $question, -1);
        }

        return $question;
    }
}
