<?php

declare(strict_types=1);

namespace Modules\ChatModule\Services\Pipeline;

use Carbon\Carbon;

class ChunkProcessor
{
public function applyDynamicThreshold (array $chunks, array $filters = [], float $similarityThreshold = 0.65, int $topK = 5): array
    {
        if ($chunks === []) {
            return [];
        }

        $scores = array_map(fn (object $c): float => (float) $c->similarity_score, $chunks);
        rsort($scores);

        if (count($scores) <= 1) {
            return array_slice($chunks, 0, $topK);
        }

        $maxGap = 0.0;
        $gapIndex = 0;
        for ($i = 0; $i < count($scores) - 1; $i++) {
            $gap = $scores[$i] - $scores[$i + 1];
            if ($gap > $maxGap) {
                $maxGap = $gap;
                $gapIndex = $i;
            }
        }

        $baseThreshold = $similarityThreshold;
        if (! empty($filters['user_ids']) || ! empty($filters['project']) || ! empty($filters['report_date_from'])) {
            $baseThreshold = 0.45;
        }

        $cutoff = $baseThreshold;
        if ($maxGap > 0.15) {
            $cutoff = max($cutoff, $scores[$gapIndex + 1]);
        } else {
            $scaled = (float) $scores[0] * 0.85;
            $cutoff = max($cutoff, $scaled);
        }

        $filtered = array_values(
            array_filter($chunks, fn (object $c): bool => (float) $c->similarity_score >= $cutoff)
        );

        $filtered = array_slice($filtered, 0, $topK);

        if ($filtered === [] && $chunks !== [] && $scores[0] >= 0.25) {
            return array_slice($chunks, 0, min(1, $topK));
        }

        return $filtered;
    }
public function applyMMR (array $chunks, bool $mmrEnabled = true, int $topK = 5, float $mmrLambda = 0.7): array
    {
        if (! $mmrEnabled || count($chunks) <= 1) {
            return $chunks;
        }

        $topK = min($topK, count($chunks));
        $selected = [];
        $candidates = $chunks;

        $selected[] = array_shift($candidates);

        while (count($selected) < $topK && $candidates !== []) {
            $bestIdx = -1;
            $bestScore = -INF;

            foreach ($candidates as $i => $cand) {
                $simScore = (float) $cand->similarity_score;
                $maxSimToSelected = 0.0;

                foreach ($selected as $sel) {
                    $penalty = $cand->document_id === $sel->document_id ? 1.0 : 0.0;
                    if ($penalty > $maxSimToSelected) {
                        $maxSimToSelected = $penalty;
                    }
                }

                $mmr = $mmrLambda * $simScore - (1.0 - $mmrLambda) * $maxSimToSelected;

                if ($mmr > $bestScore) {
                    $bestScore = $mmr;
                    $bestIdx = $i;
                }
            }

            if ($bestIdx !== -1) {
                $selected[] = $candidates[$bestIdx];
                array_splice($candidates, $bestIdx, 1);
            } else {
                break;
            }
        }

        return $selected;
    }
public function assessConfidence (array $chunks): string
    {
        $aboveThreshold = count($chunks);

        return match (true) {
            $aboveThreshold >= 3 => 'high',
            $aboveThreshold >= 1 => 'low',
            default => 'none',
        };
    }
public function reorderForLostInTheMiddle (array $chunks): array
    {
        if (count($chunks) <= 2) {
            return $chunks;
        }

        $remaining = array_slice($chunks, 1);
        $middle = (int) ceil(count($remaining) / 2);

        $ordered = array_merge(
            [$chunks[0]],
            array_slice($remaining, 0, $middle - 1),
            [$chunks[count($chunks) - 1]],
            array_slice($remaining, $middle - 1),
        );

        return $ordered;
    }
public function hasOldDocuments (array $chunks): bool
    {
        $oneYearAgo = now()->subYear();

        foreach ($chunks as $chunk) {
            if (! isset($chunk->document_created_at)) {
                continue;
            }

            try {
                if (Carbon::parse($chunk->document_created_at)->lt($oneYearAgo)) {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }


}
