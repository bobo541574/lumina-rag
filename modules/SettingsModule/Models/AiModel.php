<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

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

    public function scopeEmbedding($query)
    {
        return $query->where('type', 'embedding');
    }

    public function scopeLlm($query)
    {
        return $query->where('type', 'llm');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
