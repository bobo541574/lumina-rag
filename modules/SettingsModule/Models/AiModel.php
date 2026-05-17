<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Models;

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

    /**
     * Get the attribute casting configuration
     *
     * Defines type casts for integer, float, boolean, and array attributes
     * to ensure proper hydration from the database.
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
        ];
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

        $dims = $this->dimensions ?? 1536;

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
