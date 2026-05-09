<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $token_hash
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 */
class PersonalAccessToken extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'token_hash', 'last_used_at', 'revoked_at'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generate a new token for a user. Returns the plaintext token string and
     * the persisted model. The plaintext is shown to the user once and never
     * persisted; only its sha256 hash lives in the database.
     *
     * @return array{token: string, model: self}
     */
    public static function generate(User $user, string $name): array
    {
        $plaintext = 'fit_'.Str::random(48);
        $model = static::create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plaintext),
        ]);

        return ['token' => $plaintext, 'model' => $model];
    }

    public static function findActiveByPlaintext(string $plaintext): ?self
    {
        $hash = hash('sha256', $plaintext);

        return static::where('token_hash', $hash)
            ->whereNull('revoked_at')
            ->first();
    }

    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->revoked_at = Carbon::now();
            $this->save();
        }
    }

    public function touchLastUsed(): void
    {
        $this->last_used_at = Carbon::now();
        $this->saveQuietly();
    }
}
