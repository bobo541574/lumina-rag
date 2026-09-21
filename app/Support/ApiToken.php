<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * API Token Helper
 *
 * Generates bearer tokens and derives their one-way storage digest. Raw tokens
 * are shown to the client (or anyone who initiates login) exactly once and are
 * never persisted — only a SHA-256 digest is stored (ISO 27002:5.17, 8.5).
 *
 * Storing a digest means a database leak does not expose usable credentials and
 * the token is not included in backups or audit dumps in plaintext.
 */
final class ApiToken
{
    /**
     * Generate a new cryptographically-random 80-character hex token.
     *
     * @return string The raw token. Example: "8f3a...9c1d"
     */
    public static function make(): string
    {
        return bin2hex(random_bytes(40));
    }

    /**
     * Derive the one-way digest used for storage and lookup.
     *
     * @param  string  $rawToken  The plaintext bearer token. Example: "8f3a...9c1d"
     * @return string SHA-256 hex digest. Example: "5d4b...aa01"
     */
    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Compute the token expiry timestamp for the configured lifetime.
     *
     * @return Carbon Expiry timestamp. Example: now()->addDays(30)
     */
    public static function expiresAt(): Carbon
    {
        $ttlDays = (int) config('rag.security.api_token_ttl_days', 30);

        return Carbon::now()->addDays(max(1, $ttlDays));
    }
}
