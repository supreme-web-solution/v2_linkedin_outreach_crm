<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class V2Campaign extends Model
{
    protected $fillable = [
        'user_id', 'organization_id', 'name',
        'sequence_type', 'status', 'node_model', 'link_model', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'node_model' => 'array',
            'link_model' => 'array',
            'meta'       => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaignLeads(): HasMany
    {
        return $this->hasMany(V2CampaignLead::class, 'campaign_id');
    }

    public function campaignLists(): HasMany
    {
        return $this->hasMany(V2CampaignList::class, 'campaign_id');
    }

    public function leadProgress(): HasMany
    {
        return $this->hasMany(V2CampaignLeadProgress::class, 'campaign_id');
    }

    /**
     * Acceptance rate: accepted invites / invites sent (%).
     */
    public function acceptRate(): int
    {
        $progress = $this->leadProgress()->get(['run_status', 'acceptance_status']);
        if ($progress->isEmpty()) {
            return 0;
        }

        $sent = $progress->filter(fn ($p) => (int) $p->run_status >= 1)->count();
        $accepted = $progress->filter(fn ($p) => $p->acceptance_status === true || (int) $p->run_status >= 2)->count();

        return $sent > 0 ? (int) round(($accepted / $sent) * 100) : 0;
    }

    // ─── Template presets ────────────────────────────────────────────────────

    /**
     * Returns the 4 predefined campaign template definitions.
     * node_model uses a flat array with optional nested 'branches' for lead_gen.
     */
    public static function templates(): array
    {
        return [
            'lead_gen' => [
                'label'       => 'Lead Generation',
                'description' => 'Send an invite, then nurture accepted leads with messages and endorsements.',
                'icon'        => 'users',
                'color'       => 'blue',
                'node_model'  => [
                    ['key' => 1, 'type' => 'action', 'value' => 'send-invite',   'label' => 'Send Invite',    'config' => ['message' => '']],
                    ['key' => 2, 'type' => 'condition', 'value' => 'accepted',   'label' => 'Invite Accepted?', 'branches' => [
                        'accepted' => [
                            ['key' => 3,  'type' => 'delay',  'value' => 1,  'time' => 'hours', 'label' => 'Wait 1 hour'],
                            ['key' => 4,  'type' => 'action', 'value' => 'endorse',  'label' => 'Endorse Skills', 'config' => ['skills' => 3]],
                            ['key' => 5,  'type' => 'delay',  'value' => 1,  'time' => 'hours', 'label' => 'Wait 1 hour'],
                            ['key' => 6,  'type' => 'action', 'value' => 'message',  'label' => 'Send Message 1', 'config' => ['message' => "Hi {{firstName}}, thanks for connecting! I wanted to reach out because..."]],
                            ['key' => 7,  'type' => 'delay',  'value' => 3,  'time' => 'days',  'label' => 'Wait 3 days'],
                            ['key' => 8,  'type' => 'action', 'value' => 'message',  'label' => 'Send Message 2', 'config' => ['message' => "Hi {{firstName}}, just following up — did you get a chance to look at my previous message?"]],
                        ],
                        'not_accepted' => [
                            ['key' => 9,  'type' => 'delay',  'value' => 5,  'time' => 'days',  'label' => 'Wait 5 days'],
                            ['key' => 10, 'type' => 'action', 'value' => 'profile-view', 'label' => 'View Profile',  'config' => []],
                            ['key' => 11, 'type' => 'delay',  'value' => 5,  'time' => 'days',  'label' => 'Wait 5 days'],
                            ['key' => 12, 'type' => 'action', 'value' => 'profile-view',  'label' => 'View Profile',  'config' => []],
                            ['key' => 13, 'type' => 'delay',  'value' => 20, 'time' => 'days',  'label' => 'Wait 20 days'],
                        ],
                    ]],
                    ['key' => 99, 'type' => 'end', 'label' => 'End'],
                ],
            ],

            'endorse' => [
                'label'       => 'Endorse My Skills',
                'description' => 'Endorse connections\' skills to encourage reciprocal endorsements.',
                'icon'        => 'star',
                'color'       => 'yellow',
                'node_model'  => [
                    ['key' => 1, 'type' => 'action', 'value' => 'endorse', 'label' => 'Endorse Skills', 'config' => ['skills' => 5]],
                    ['key' => 2, 'type' => 'delay',  'value' => 5,  'time' => 'days', 'label' => 'Wait 5 days'],
                    ['key' => 3, 'type' => 'action', 'value' => 'endorse', 'label' => 'Endorse Skills', 'config' => ['skills' => 3]],
                    ['key' => 4, 'type' => 'delay',  'value' => 10, 'time' => 'days', 'label' => 'Wait 10 days'],
                    ['key' => 5, 'type' => 'action', 'value' => 'endorse', 'label' => 'Endorse Skills', 'config' => ['skills' => 3]],
                    ['key' => 99, 'type' => 'end',   'label' => 'End'],
                ],
            ],

            'profile_views' => [
                'label'       => 'Extra Profile Views',
                'description' => 'Increase your visibility by viewing profiles and engaging with posts.',
                'icon'        => 'eye',
                'color'       => 'purple',
                'node_model'  => [
                    ['key' => 1,  'type' => 'action', 'value' => 'profile-view', 'label' => 'View Profile', 'config' => []],
                    ['key' => 2,  'type' => 'delay',  'value' => 5,  'time' => 'hours', 'label' => 'Wait 5 hours'],
                    ['key' => 3,  'type' => 'action', 'value' => 'profile-view', 'label' => 'View Profile', 'config' => []],
                    ['key' => 4,  'type' => 'delay',  'value' => 5,  'time' => 'days',  'label' => 'Wait 5 days'],
                    ['key' => 5,  'type' => 'action', 'value' => 'profile-view', 'label' => 'View Profile', 'config' => []],
                    ['key' => 6,  'type' => 'delay',  'value' => 5,  'time' => 'days',  'label' => 'Wait 5 days'],
                    ['key' => 7,  'type' => 'action', 'value' => 'like-post',    'label' => 'Like a Post',   'config' => []],
                    ['key' => 99, 'type' => 'end',    'label' => 'End'],
                ],
            ],

            'custom' => [
                'label'       => 'Custom Campaign',
                'description' => 'Start from scratch and build your own sequence of steps.',
                'icon'        => 'settings',
                'color'       => 'slate',
                'node_model'  => [
                    ['key' => 1,  'type' => 'action', 'value' => 'send-invite', 'label' => 'Send Invite', 'config' => ['message' => '']],
                    ['key' => 99, 'type' => 'end',    'label' => 'End'],
                ],
            ],
        ];
    }
}
