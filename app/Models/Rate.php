<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property float $hourly_rate
 * @property bool $is_archived
 */
class Rate extends Model
{
    protected $fillable = ['name', 'hourly_rate', 'is_archived'];

    protected function casts(): array
    {
        return [
            'hourly_rate' => 'decimal:2',
            'is_archived' => 'boolean',
        ];
    }

    public function label(): string
    {
        return $this->name.' — £'.number_format((float) $this->hourly_rate, 2).'/hr';
    }
}
