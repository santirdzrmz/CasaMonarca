<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'id',
    'occurred_at',
    'actor_user_id',
    'actor_role',
    'acting_department',
    'event_type',
    'resource_type',
    'resource_id',
    'document_id',
    'revision_id',
    'outcome',
    'request_id',
    'ip_address',
    'user_agent',
    'session_id_hash',
    'metadata',
])]
class AuditEvent extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (self $auditEvent): void {
            if (! is_string($auditEvent->getKey()) || $auditEvent->getKey() === '') {
                $auditEvent->setAttribute($auditEvent->getKeyName(), (string) Str::uuid());
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
