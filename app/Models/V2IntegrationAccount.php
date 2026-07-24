<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2IntegrationAccount extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_account_id',
        'provider_identity_id',
        'status',
        'meta',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the Unipile account_id stored in meta for this integration.
     * Unipile requires this as `account_id` in every API request.
     */
    public function getUnipileAccountId(): ?string
    {
        return $this->meta['unipile_account_id'] ?? null;
    }

    /**
     * Find the active LinkedIn account for a user and return its Unipile account ID.
     * Returns null if no account is connected yet.
     */
    public static function activeUnipileAccountId(int $userId): ?string
    {
        return static::activeUnipileAccountIdForProvider($userId, 'linkedin');
    }

    public static function activeUnipileAccountIdForProvider(int $userId, string $provider): ?string
    {
        $account = static::query()
            ->where('user_id', $userId)
            ->where('provider', $provider)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        return $account?->getUnipileAccountId();
    }
}
