<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property Role $role
 * @property string|null $asana_access_token
 * @property string|null $asana_refresh_token
 * @property Carbon|null $asana_token_expires_at
 * @property string|null $asana_user_gid
 * @property string|null $asana_workspace_gid
 * @property Collection<int, Project> $projects
 * @property Collection<int, TimeEntry> $timeEntries
 */
class User extends Authenticatable
{
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
        'email',
        'name',
        'role',
        'role_title',
        'is_contractor',
        'default_hourly_rate',
        'weekly_capacity_hours',
        'is_active',
        'last_login_at',
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
            ->withPivot(['hourly_rate_override']);
    }

    /** @return HasMany<TimeEntry, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isManager(): bool
    {
        return $this->role === Role::Manager || $this->role === Role::Admin;
    }
}
