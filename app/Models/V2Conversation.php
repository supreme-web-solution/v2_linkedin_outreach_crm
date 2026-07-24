<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class V2Conversation extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_chat_id',
        'lead_id',
        'status',
        'last_message_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(V2Lead::class, 'lead_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(V2Message::class, 'conversation_id');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(V2Call::class, 'conversation_id');
    }

    /**
     * Threads created or tracked by Call Manager (not bulk-imported LinkedIn inbox).
     */
    public function scopeManagedByCallManager(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('meta->source', 'call_manager')
                ->orWhereHas('calls');
        });
    }

    /**
     * Outreach campaign inbox threads only — never Call Manager.
     */
    public function scopeForOutreachInbox(Builder $query): Builder
    {
        return $query
            ->whereDoesntHave('calls')
            ->where(function (Builder $q) {
                $q->whereNull('meta->source')
                    ->orWhere('meta->source', '!=', 'call_manager');
            })
            ->whereNotNull('meta->outreach_campaign_id');
    }

    /**
     * Multi-channel outreach inbox threads (WhatsApp, Instagram, etc.).
     */
    public function scopeManagedByUnifiedInbox(Builder $query): Builder
    {
        return $query
            ->forOutreachInbox()
            ->where('meta->source', 'unified_inbox');
    }

    /**
     * Inbox threads for a platform — outreach campaigns only, not Call Manager.
     */
    public function scopeForInboxPlatform(Builder $query, string $platform): Builder
    {
        return $query
            ->forOutreachInbox()
            ->where('provider', $platform);
    }

    public function isInboxThread(): bool
    {
        if ($this->calls()->exists()) {
            return false;
        }

        $meta = is_array($this->meta) ? $this->meta : [];
        $source = $meta['source'] ?? null;
        $campaignId = (int) ($meta['outreach_campaign_id'] ?? 0);

        if ($source === 'call_manager' || $campaignId <= 0) {
            return false;
        }

        return $source === 'unified_inbox' || $source === null || $source === 'outreach';
    }

    public function isCallManagerThread(): bool
    {
        if ($this->calls()->exists()) {
            return true;
        }

        return is_array($this->meta) && ($this->meta['source'] ?? null) === 'call_manager';
    }
}
