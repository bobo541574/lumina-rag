<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypted String Cast
 *
 * Like Laravel's built-in `encrypted` cast, but tolerant of empty and
 * legacy-plaintext values: null/'' pass through untouched (never encrypted,
 * never decrypted) and values that fail to decrypt are returned verbatim
 * instead of throwing. The SettingsModuleSeeder upgrades legacy plaintext
 * keys on every seed.
 *
 * Credentials at rest are encrypted (ISO 27002:8.24); masking on output is
 * handled separately by the model's $hidden attribute.
 */
class EncryptedString implements CastsAttributes
{
    /**
     * Decrypt a stored ciphertext value, passing empty/legacy values through.
     *
     * @param  object  $model  The owning model. Example: AiModel
     * @param  string  $key  The attribute name. Example: "api_key"
     * @param  mixed  $value  The raw attribute value from the database. Example: "eyJ..."
     * @param  array  $attributes  The full attribute array. Example: []
     * @return mixed Decrypted plaintext, or the raw value when empty/undecryptable. Example: "sk-proj-..."
     */
    public function get($model, string $key, $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // Legacy plaintext (or a rotated app key) — surface as-is so the
            // app keeps working; the seeder upgrades plaintext on re-run.
            return $value;
        }
    }

    /**
     * Encrypt a plaintext value before persistence, leaving empty values alone.
     *
     * @param  object  $model  The owning model. Example: AiModel
     * @param  string  $key  The attribute name. Example: "api_key"
     * @param  mixed  $value  The plaintext value being assigned. Example: "sk-proj-..."
     * @param  array  $attributes  The full attribute array. Example: []
     * @return mixed Ciphertext string, or the raw value when empty. Example: "eyJ..."
     */
    public function set($model, string $key, $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return Crypt::encryptString($value);
    }
}
