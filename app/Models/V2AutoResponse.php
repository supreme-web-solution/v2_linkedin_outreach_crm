<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2AutoResponse extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'message_type',
        'message_keywords',
        'message_body',
        'platforms',
        'attachments',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'platforms' => 'array',
            'attachments' => 'array',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Empty platforms means the rule applies to all connected channels.
     *
     * @return array<int, string>
     */
    public function platformKeys(): array
    {
        $platforms = $this->platforms;
        if (! is_array($platforms)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($key) => strtolower(trim((string) $key)),
            $platforms
        )));
    }

    public function appliesToAllPlatforms(): bool
    {
        return $this->platformKeys() === [];
    }

    public function appliesToProvider(string $provider): bool
    {
        $keys = $this->platformKeys();
        if ($keys === []) {
            return true;
        }

        $normalized = strtolower(trim($provider));

        return in_array($normalized, $keys, true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
