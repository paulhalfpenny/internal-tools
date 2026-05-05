<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $level
 * @property string $event
 * @property array<string, mixed>|null $context
 * @property string|null $subject_type
 * @property int|string|null $subject_id
 * @property Carbon $created_at
 */
class AsanaSyncLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['level', 'event', 'context', 'subject_type', 'subject_id'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @param  array<string, mixed>  $context */
    public static function info(string $event, array $context = [], ?Model $subject = null): self
    {
        return self::write('info', $event, $context, $subject);
    }

    /** @param  array<string, mixed>  $context */
    public static function warn(string $event, array $context = [], ?Model $subject = null): self
    {
        return self::write('warn', $event, $context, $subject);
    }

    /** @param  array<string, mixed>  $context */
    public static function error(string $event, array $context = [], ?Model $subject = null): self
    {
        return self::write('error', $event, $context, $subject);
    }

    /** @param  array<string, mixed>  $context */
    private static function write(string $level, string $event, array $context, ?Model $subject): self
    {
        return self::create([
            'level' => $level,
            'event' => $event,
            'context' => $context,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
        ]);
    }
}
