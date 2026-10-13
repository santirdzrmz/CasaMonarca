<?php

namespace App\Models;

use App\Enums\Department;
use App\Enums\UserRole;
use App\Enums\UserStatus;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name',
    'email',
    'curp',
    'role',
    'status',
    'password',
    'two_factor_enabled',
    'two_factor_secret',
    'suspended_at',
    'suspended_by_user_id',
    'suspension_reason',
    'last_sign_in_at',
])]
#[Hidden(['password', 'remember_token', 'two_factor_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_enabled' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'suspended_at' => 'datetime',
            'last_sign_in_at' => 'datetime',
        ];
    }

    public function isActiveAccount(): bool
    {
        return ($this->status ?? UserStatus::default()) === UserStatus::Active;
    }

    public function isSuspended(): bool
    {
        return ($this->status ?? UserStatus::default()) === UserStatus::Suspended;
    }

    public function hasTotpEnabled(): bool
    {
        return $this->two_factor_enabled && filled($this->two_factor_secret);
    }

    public function departmentAssignments(): HasMany
    {
        return $this->hasMany(UserDepartment::class);
    }

    /**
     * @return list<string>
     */
    public function departmentValues(): array
    {
        return $this->departmentAssignments()
            ->pluck('department')
            ->map(fn (Department $department): string => $department->value)
            ->sort()
            ->values()
            ->all();
    }

    public function hasDepartment(Department $department): bool
    {
        return $this->departmentAssignments()->where('department', $department->value)->exists();
    }

    public function webauthnCredentials(): HasMany
    {
        return $this->hasMany(WebauthnCredential::class);
    }

    public function browserDevices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function ownedDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'owner_user_id');
    }

    public function uploadedDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'uploaded_by_user_id');
    }

    public function documentRevisions(): HasMany
    {
        return $this->hasMany(DocumentRevision::class, 'created_by_user_id');
    }

    public function documentSignatures(): HasMany
    {
        return $this->hasMany(DocumentSignature::class, 'signed_by_user_id');
    }

    public function migrantRegistrySignatures(): HasMany
    {
        return $this->hasMany(MigrantRegistrySignature::class, 'actor_user_id');
    }

    public function createdInvites(): HasMany
    {
        return $this->hasMany(AccountInvite::class, 'invited_by_user_id');
    }
}
