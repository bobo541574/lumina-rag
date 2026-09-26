<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Small vector helpers shared by the SQLite vector fallback and the
 * semantic answer cache.
 */
final class VectorMath
{
    /**
     * Cosine similarity of two vectors (compared over the shorter length).
     *
     * @param  array<float>  $a  First vector. Example: [0.1, 0.2, 0.3]
     * @param  array<float>  $b  Second vector. Example: [0.4, 0.5, 0.6]
     * @return float Between -1 and 1; 0.0 when either vector has zero magnitude. Example: 0.975
     */
    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $len = min(count($a), count($b));

        for ($i = 0; $i < $len; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $normA += $x * $x;
            $normB += $y * $y;
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
