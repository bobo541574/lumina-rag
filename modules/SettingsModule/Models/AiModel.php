<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * AI Model
 *
 * Eloquent model representing an AI model configuration (embedding or LLM)
 * stored in the ai_models table. Each record defines a specific model instance
 * including provider, credentials, dimensions, and operational parameters.
 *
 * The model uses ULID primary keys and provides helper methods for determining
 * the target vector shard collection (ve_{dims}) based on embedding dimensions.
 * Scopes are provided for filtering by type and active status.
 *
 * @property string $id ULID primary key
 * @property string $name Human-readable display name. Example: "nomic-embed-text"
 * @property string $type Model type: "embedding" or "llm". Example: "embedding"
 * @property string $provider Provider name: "openai" or "ollama". Example: "ollama"
 * @property string $model Provider-specific model identifier. Example: "nomic-embed-text:latest"
 * @property string|null $api_key API key for the provider. Example: "sk-..."
 * @property string|null $base_url Base URL for the provider API. Example: "http://localhost:11434"
 * @property string|null $collection Override vector shard collection name. Example: "ve_768"
 * @property int|null $dimensions Embedding vector dimensions. Example: 768
 * @property int|null $batch_size Batch size for embedding generation. Example: 100
 * @property int|null $cache_ttl Cache TTL in seconds for embeddings. Example: 86400
 * @property float|null $temperature LLM temperature (0-2). Example: 0.3
 * @property int|null $max_context_tokens Maximum context window tokens. Example: 32768
 * @property int|null $timeout Request timeout in seconds. Example: 30
 * @property string|null $description Description of the model. Example: "Fast general-purpose embedding (768d)"
 * @property array|null $settings Arbitrary settings JSON. Example: {"max_tokens": 4096}
 * @property bool $is_active Whether the model is active for use. Example: true
 * @property int $sort_order Display/priority order. Example: 1
 */
class AiModel extends Model
{
    use HasUlids;

    protected $table = 'ai_models';

    /** Providers ProviderFactory can build an embedding client for. */
    public const EMBEDDING_PROVIDERS = ['openai', 'ollama', 'gemini'];

    /** Providers ProviderFactory can build an LLM client for. */
    public const LLM_PROVIDERS = ['openai', 'ollama', 'gemini', 'claude', 'deepseek'];

    protected $fillable = [
        'name',
        'type',
        'provider',
        'model',
        'api_key',
        'base_url',
        'collection',
        'dimensions',
        'batch_size',
        'cache_ttl',
        'temperature',
        'max_context_tokens',
        'timeout',
        'description',
        'settings',
        'is_active',
        'sort_order',
    ];

    // Provider credentials are never serialized to API responses (exposed only
    // as a boolean has_api_key to the UI). At rest, api_key is encrypted.
    protected $hidden = [
        'api_key',
    ];

    protected $appends = [
        'has_api_key',
    ];

    /**
     * Get the attribute casting configuration
     *
     * Defines type casts for integer, float, boolean, and array attributes
     * to ensure proper hydration from the database. api_key is encrypted at
     * rest (ISO 27002:8.24).
     *
     * @return array<string, string> Attribute cast map
     *                               Example: ["dimensions" => "integer", "is_active" => "boolean", "settings" => "array"]
     */
    protected function casts(): array
    {
        return [
            'dimensions' => 'integer',
            'batch_size' => 'integer',
            'cache_ttl' => 'integer',
            'temperature' => 'float',
            'max_context_tokens' => 'integer',
            'timeout' => 'integer',
            'settings' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'api_key' => EncryptedString::class,
        ];
    }

    /**
     * Report whether this model has a credential configured (without exposing it).
     *
     * @return bool True when api_key is present. Example: true
     */
    public function getHasApiKeyAttribute(): bool
    {
        return $this->api_key !== null && $this->api_key !== '';
    }

    /**
     * Get the vector shard collection name
     *
     * Returns the collection/overridden name if set, otherwise derives the
     * nearest standard shard table name (ve_{384|768|1024|1536|3072}) from
     * the model's dimensions. Falls back to ve_1536 if dimensions are null.
     *
     * @return string The collection name. Example: "ve_768"
     */
    public function getCollection(): string
    {
        if ($this->collection !== null) {
            return $this->collection;
        }

        $dims = $this->dimensions();

        $dimMap = [384, 768, 1024, 1536, 3072];
        $nearest = 1536;
        foreach ($dimMap as $d) {
            if (abs($d - $dims) < abs($nearest - $dims)) {
                $nearest = $d;
            }
        }

        return "ve_{$nearest}";
    }

    /**
     * Cache TTL for embedding results
     *
     * Returns the model's cache_ttl column, falling back to the embedding
     * cache_ttl from config/rag.php when the column is null.
     *
     * @return int Cache TTL in seconds. Example: 86400
     */
    public function cacheTtl(): int
    {
        return $this->cache_ttl ?? (int) config('rag.embedding.cache_ttl', 86400);
    }

    /**
     * Maximum context window for LLM generation
     *
     * Returns the model's max_context_tokens column, falling back to the LLM
     * max_context_tokens from config/rag.php when the column is null.
     *
     * @return int Maximum context tokens. Example: 32768
     */
    public function maxContextTokens(): int
    {
        return $this->max_context_tokens ?? (int) config('rag.llm.max_context_tokens', 32768);
    }

    /**
     * Provider model identifier
     *
     * Returns the model's `model` column, falling back to the type-specific
     * model name from config/rag.php when the column is null.
     *
     * @return string The model identifier. Example: "nomic-embed-text:latest"
     */
    public function modelName(): string
    {
        return $this->model
            ?? (string) config("rag.{$this->configSection()}.model", '');
    }

    /**
     * Resolve a model identifier for a possibly-null AiModel record.
     *
     * Returns modelName() when a record is present, otherwise the type-specific
     * model name from config/rag.php. Used when an active model may be absent
     * (e.g. no DB available) but a stable identifier is still required for
     * cache scoping and retrieval filters.
     *
     * @param  self|null  $model  The AiModel record, or null when unconfigured. Example: AiModel::find("01J...")
     * @param  string  $type  Model type used for the config fallback: "embedding" or "llm". Example: "llm"
     * @return string The resolved model identifier. Example: "qwen3.5:9b"
     */
    public static function resolveModelName(?self $model, string $type): string
    {
        return $model?->modelName() ?? (string) config($type === 'embedding' ? 'rag.embedding.model' : 'rag.llm.model', '');
    }

    /**
     * Provider credential
     *
     * Returns the model's decrypted api_key when set, otherwise falls back to
     * the provider-specific (and type-specific) API key from config/rag.php.
     * Ollama models have no credential and are never handed this value.
     *
     * @return string The resolved API key. Example: "sk-proj-..."
     */
    public function apiKey(): string
    {
        if ($this->api_key !== null && $this->api_key !== '') {
            return $this->api_key;
        }

        $section = $this->configSection();

        return (string) match ($this->provider) {
            'gemini' => config("rag.{$section}.gemini_api_key", ''),
            'claude' => config("rag.{$section}.claude_api_key", ''),
            'deepseek' => config("rag.{$section}.deepseek_api_key", ''),
            default => config("rag.{$section}.api_key", ''),
        };
    }

    /**
     * Provider API base URL
     *
     * Returns the model's base_url when set, otherwise the provider default.
     * For Ollama that is `rag.{type}.base_url` (RAG_*_BASE_URL is the Ollama
     * server URL); hosted providers use their public API endpoint.
     *
     * @return string The resolved base URL. Example: "http://localhost:11434"
     */
    public function baseUrl(): string
    {
        if ($this->base_url !== null && $this->base_url !== '') {
            return $this->base_url;
        }

        return match ($this->provider) {
            'ollama' => (string) config("rag.{$this->configSection()}.base_url", 'http://localhost:11434'),
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta',
            'claude' => 'https://api.anthropic.com/v1',
            'deepseek' => 'https://api.deepseek.com/v1',
            default => 'https://api.openai.com/v1',
        };
    }

    /**
     * Build an unsaved model from config/rag.php.
     *
     * Used when no AiModel row is configured (fresh install, DB unavailable)
     * so provider construction still goes through ProviderFactory with the
     * same fallback rules as database models.
     *
     * @param  string  $type  "embedding" or "llm". Example: "llm"
     * @return self Transient model; never persisted. Example: AiModel::fromConfig('llm')->modelName()
     */
    public static function fromConfig(string $type): self
    {
        $model = new self;
        $model->forceFill([
            'type' => $type,
            'provider' => (string) config("rag.{$type}.provider", 'ollama'),
            // Other columns stay null so each resolver falls back to config.
            'dimensions' => $type === 'embedding' ? config('rag.embedding.dimensions') : null,
            'is_active' => true,
        ]);

        return $model;
    }

    /**
     * @return string The config/rag.php section for this model's type. Example: "embedding"
     */
    private function configSection(): string
    {
        return $this->type === 'embedding' ? 'embedding' : 'llm';
    }

    /**
     * Embedding vector dimensions
     *
     * Returns the model's dimensions column when set, otherwise the
     * provider-typical default (1536 for OpenAI, 768 otherwise).
     *
     * @return int The resolved dimensions. Example: 768
     */
    public function dimensions(): int
    {
        return $this->dimensions ?? ($this->provider === 'openai' ? 1536 : 768);
    }

    /**
     * Batch size for embedding generation
     *
     * Returns the model's batch_size column, falling back to the embedding
     * batch_size from config/rag.php when the column is null.
     *
     * @return int The resolved batch size. Example: 100
     */
    public function batchSize(): int
    {
        return $this->batch_size ?? (int) config('rag.embedding.batch_size', 100);
    }

    /**
     * Request timeout in seconds
     *
     * Returns the model's timeout column, falling back to the type-specific
     * timeout from config/rag.php when the column is null.
     *
     * @return int The resolved timeout. Example: 30
     */
    public function timeout(): int
    {
        return $this->timeout
            ?? (int) config("rag.{$this->configSection()}.timeout", 30);
    }

    /**
     * LLM generation temperature
     *
     * Returns the model's temperature column, falling back to the LLM
     * temperature from config/rag.php when the column is null.
     *
     * @return float The resolved temperature. Example: 0.3
     */
    public function temperature(): float
    {
        return $this->temperature ?? (float) config('rag.llm.temperature', 0.3);
    }

    /**
     * Resolve generation temperature for a possibly-null AiModel record.
     *
     * Returns temperature() when a record is present, otherwise the LLM
     * temperature from config/rag.php. Used when no active LLM model exists.
     *
     * @param  self|null  $model  The AiModel record, or null when unconfigured. Example: AiModel::find("01J...")
     * @return float The resolved temperature. Example: 0.3
     */
    public static function resolveTemperature(?self $model): float
    {
        return $model?->temperature() ?? (float) config('rag.llm.temperature', 0.3);
    }

    /**
     * Scope query to embedding models only
     *
     * @param  Builder  $query  The query builder instance
     * @return Builder
     */
    public function scopeEmbedding($query)
    {
        return $query->where('type', 'embedding');
    }

    /**
     * Scope query to LLM models only
     *
     * @param  Builder  $query  The query builder instance
     * @return Builder
     */
    public function scopeLlm($query)
    {
        return $query->where('type', 'llm');
    }

    /**
     * Scope query to active models only
     *
     * @param  Builder  $query  The query builder instance
     * @return Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
