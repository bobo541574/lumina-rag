<?php

declare(strict_types=1);

namespace Modules\SettingsModule\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasUlids;

    protected $table = 'settings';

    protected $fillable = [
        'key',
        'value',
        'type',
        'label',
        'group',
    ];

    protected function casts(): array
    {
        return [
            'type' => 'string',
        ];
    }
}
