<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKey extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'key_hash',
        'key_prefix',
        'environment',
        'name',
        'last_used_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // ---------------------------------------------------------------
    // Methods
    // ---------------------------------------------------------------

    /**
     * Check whether this API key has been revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Find an API key by its raw (unhashed) value, excluding revoked keys.
     */
    public static function findByRawKey(string $rawKey): ?static
    {
        return static::where('key_hash', hash('sha256', $rawKey))
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * Update the last_used_at timestamp to the current time.
     */
    public function touchLastUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }
}
