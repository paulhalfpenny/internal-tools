<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property Role $role
 * @property string|null $google_access_token
 * @property string|null $google_refresh_token
 * @property Carbon|null $google_token_expires_at
 * @property string|null $asana_access_token
 * @property string|null $asana_refresh_token
 * @property Carbon|null $asana_token_expires_at
 * @property string|null $asana_user_gid
 * @property string|null $asana_workspace_gid
 * @property string|null $slack_user_id
 * @property Carbon|null $notifications_paused_until
 * @property bool $email_notifications_enabled
 * @property bool $slack_notifications_enabled
 * @property int|null $reports_to_user_id
 * @property Collection<int, Project> $projects
 * @property Collection<int, TimeEntry> $timeEntries
 * @property ?User $manager
 * @property Collection<int, User> $directReports
 */
class User extends Authenticatable
{
    public const DEFAULT_WEEKLY_TARGET_HOURS = 40.0;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'google_sub',
        'google_access_token',
        'google_refresh_token',
        'google_token_expires_at',
        'asana_access_token',
        'asana_refresh_token',
        'asana_token_expires_at',
        'asana_user_gid',
        'asana_workspace_gid',
        'slack_user_id',
        'email',
        'name',
        'role',
        'role_title',
        'is_contractor',
        'default_hourly_rate',
        'rate_id',
        'weekly_capacity_hours',
        'is_active',
        'last_login_at',
        'notifications_paused_until',
        'email_notifications_enabled',
        'slack_notifications_enabled',
        'reports_to_user_id',
    ];

    protected $hidden = [
        'google_access_token',
        'google_refresh_token',
        'asana_access_token',
        'asana_refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'is_contractor' => 'boolean',
            'is_active' => 'boolean',
            'default_hourly_rate' => 'decimal:2',
            'weekly_capacity_hours' => 'decimal:2',
            'last_login_at' => 'datetime',
            'google_access_token' => 'encrypted',
            'google_refresh_token' => 'encrypted',
            'google_token_expires_at' => 'datetime',
            'asana_access_token' => 'encrypted',
            'asana_refresh_token' => 'encrypted',
            'asana_token_expires_at' => 'datetime',
            'notifications_paused_until' => 'date',
            'email_notifications_enabled' => 'boolean',
            'slack_notifications_enabled' => 'boolean',
        ];
    }

    public function asanaConnected(): bool
    {
        return $this->asana_access_token !== null && $this->asana_user_gid !== null;
    }

    /** @return BelongsToMany<Project, $this> */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)
            ->withPivot(['hourly_rate_override', 'rate_id']);
    }

    /** @return BelongsTo<Rate, $this> */
    public function rate(): BelongsTo
    {
        return $this->belongsTo(Rate::class);
    }

    /** @return HasMany<TimeEntry, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /** @return HasMany<PersonalAccessToken, $this> */
    public function personalAccessTokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class);
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reports_to_user_id');
    }

    /** @return HasMany<User, $this> */
    public function directReports(): HasMany
    {
        return $this->hasMany(User::class, 'reports_to_user_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isManager(): bool
    {
        return $this->role === Role::Manager || $this->role === Role::Admin;
    }

    public function effectiveWeeklyTarget(): float
    {
        $target = (float) $this->weekly_capacity_hours;

        return $target > 0 ? $target : self::DEFAULT_WEEKLY_TARGET_HOURS;
    }

    public function notificationsArePaused(?\DateTimeInterface $on = null): bool
    {
        if ($this->notifications_paused_until === null) {
            return false;
        }

        $reference = $on === null ? today()->toDateString() : Carbon::instance($on)->toDateString();

        return $this->notifications_paused_until->toDateString() >= $reference;
    }

    /**
     * Active users who can currently receive automated reminders.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeNotificationsActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $q) {
                $q->whereNull('notifications_paused_until')
                    ->orWhere('notifications_paused_until', '<', today()->toDateString());
            });
    }
}
