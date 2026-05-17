<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Term Alias
 *
 * Eloquent model representing a term alias mapping used for cross-language
 * and variant-aware search expansion. Maps non-canonical terms (Burmese
 * translations, abbreviations, alternate spellings) to their canonical
 * English equivalents.
 *
 * Aliases are categorized by type (project, technical, general) and support
 * active/inactive toggling. The model uses ULID primary keys and provides
 * scopes for filtering by type and active status.
 *
 * @property string $id ULID primary key
 * @property string $type Alias category: "project", "technical", or "general". Example: "project"
 * @property string $alias The non-canonical term. Example: "အိုရီယွန်"
 * @property string $canonical The canonical English term. Example: "Orion"
 * @property string|null $description Description of the alias mapping. Example: "Project name: Orion in Burmese"
 * @property bool $is_active Whether the alias is active for search expansion. Example: true
 */
class TermAlias extends Model
{
    use HasUlids;

    protected $table = 'term_aliases';

    protected $fillable = [
        'type',
        'alias',
        'canonical',
        'description',
        'is_active',
    ];

    /**
     * Get the attribute casting configuration
     *
     * @return array<string, string> Attribute cast map
     *                               Example: ["is_active" => "boolean"]
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope query to active aliases only
     *
     * @param  Builder  $query  The query builder instance
     * @return Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query to aliases of a specific type
     *
     * @param  Builder  $query  The query builder instance
     * @param  string  $type  The alias type to filter by. Example: "project"
     * @return Builder
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
