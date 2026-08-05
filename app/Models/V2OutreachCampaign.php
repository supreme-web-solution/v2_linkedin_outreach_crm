<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class V2OutreachCampaign extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'name',
        'template_type',
        'status',
        'node_model',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'node_model' => 'array',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function outreachLeads(): HasMany
    {
        return $this->hasMany(V2OutreachLead::class, 'outreach_campaign_id');
    }

    public function outreachLists(): HasMany
    {
        return $this->hasMany(V2OutreachList::class, 'outreach_campaign_id');
    }

    public function leadProgress(): HasMany
    {
        return $this->hasMany(V2OutreachLeadProgress::class, 'outreach_campaign_id');
    }

    public function nodeEvents(): HasMany
    {
        return $this->hasMany(V2OutreachNodeEvent::class, 'outreach_campaign_id');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function templates(): array
    {
        return [
            'linkedin_only' => [
                'label' => 'LinkedIn Outreach',
                'description' => 'Visit profile, connect, and message. Works immediately with any LinkedIn list.',
                'icon' => 'users',
                'color' => 'blue',
                'node_model' => [
                    ['key' => 1, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'visit_profile', 'label' => 'Visit Profile', 'config' => []],
                    ['key' => 2, 'type' => 'delay', 'value' => 1, 'time' => 'days', 'label' => 'Wait 1 day'],
                    ['key' => 3, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_invite', 'label' => 'Send Invite', 'config' => ['message' => '']],
                    ['key' => 4, 'type' => 'delay', 'value' => 2, 'time' => 'days', 'label' => 'Wait 2 days'],
                    ['key' => 5, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_message', 'label' => 'Send Message', 'config' => ['message' => 'Thanks for connecting, {{firstName}}!']],
                    ['key' => 99, 'type' => 'end', 'label' => 'End'],
                ],
            ],
            'linkedin_email' => [
                'label' => 'LinkedIn → Email',
                'description' => 'Connect on LinkedIn, email if no accept. Fetch emails from LinkedIn profiles before launch.',
                'icon' => 'layers',
                'color' => 'blue',
                'node_model' => [
                    ['key' => 1, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_invite', 'label' => 'Send Invite', 'config' => ['message' => '']],
                    ['key' => 2, 'type' => 'delay', 'value' => 3, 'time' => 'days', 'label' => 'Wait 3 days'],
                    ['key' => 3, 'type' => 'condition', 'channel' => 'linkedin', 'condition' => 'invite_accepted', 'label' => 'Invite Accepted?', 'branches' => [
                        'accepted' => [
                            ['key' => 4, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_message', 'label' => 'Send Message', 'config' => ['message' => 'Thanks for connecting, {{firstName}}!']],
                        ],
                        'not_accepted' => [
                            ['key' => 5, 'type' => 'action', 'channel' => 'email', 'action' => 'send_email', 'label' => 'Send Email', 'config' => ['subject' => 'Quick intro', 'body' => 'Hi {{firstName}}, I tried reaching you on LinkedIn and wanted to follow up here.']],
                        ],
                    ]],
                    ['key' => 99, 'type' => 'end', 'label' => 'End'],
                ],
            ],
            'linkedin_whatsapp' => [
                'label' => 'LinkedIn → WhatsApp',
                'description' => 'LinkedIn invite first, WhatsApp follow-up. Fetch phone + verify WhatsApp before launch.',
                'icon' => 'message-circle',
                'color' => 'green',
                'node_model' => [
                    ['key' => 1, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_invite', 'label' => 'Send Invite', 'config' => ['message' => '']],
                    ['key' => 2, 'type' => 'delay', 'value' => 2, 'time' => 'days', 'label' => 'Wait 2 days'],
                    ['key' => 3, 'type' => 'condition', 'channel' => 'linkedin', 'condition' => 'invite_accepted', 'label' => 'Invite Accepted?', 'branches' => [
                        'accepted' => [
                            ['key' => 4, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_message', 'label' => 'LinkedIn Message', 'config' => ['message' => 'Thanks {{firstName}} — quick question for you.']],
                        ],
                        'not_accepted' => [
                            ['key' => 5, 'type' => 'action', 'channel' => 'whatsapp', 'action' => 'send_message', 'label' => 'WhatsApp Message', 'config' => ['message' => 'Hi {{firstName}}, I reached out on LinkedIn — happy to chat here if easier.']],
                        ],
                    ]],
                    ['key' => 99, 'type' => 'end', 'label' => 'End'],
                ],
            ],
            'multichannel' => [
                'label' => 'LinkedIn + Email + WhatsApp',
                'description' => 'Full stack: LinkedIn connect, email backup, WhatsApp last touch. Prepare all contacts before launch.',
                'icon' => 'layers',
                'color' => 'violet',
                'node_model' => [
                    ['key' => 1, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_invite', 'label' => 'Send Invite', 'config' => ['message' => '']],
                    ['key' => 2, 'type' => 'delay', 'value' => 3, 'time' => 'days', 'label' => 'Wait 3 days'],
                    ['key' => 3, 'type' => 'condition', 'channel' => 'linkedin', 'condition' => 'invite_accepted', 'label' => 'Invite Accepted?', 'branches' => [
                        'accepted' => [
                            ['key' => 4, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_message', 'label' => 'LinkedIn Message', 'config' => ['message' => 'Thanks for connecting, {{firstName}}!']],
                        ],
                        'not_accepted' => [
                            ['key' => 5, 'type' => 'action', 'channel' => 'email', 'action' => 'send_email', 'label' => 'Email Follow-up', 'config' => ['subject' => 'Following up', 'body' => 'Hi {{firstName}}, I tried LinkedIn — sharing a quick note by email.']],
                            ['key' => 6, 'type' => 'delay', 'value' => 2, 'time' => 'days', 'label' => 'Wait 2 days'],
                            ['key' => 7, 'type' => 'action', 'channel' => 'whatsapp', 'action' => 'send_message', 'label' => 'WhatsApp Touch', 'config' => ['message' => 'Hi {{firstName}}, just checking if you saw my note — happy to chat here.']],
                        ],
                    ]],
                    ['key' => 99, 'type' => 'end', 'label' => 'End'],
                ],
            ],
            'email_only' => [
                'label' => 'Email Sequence',
                'description' => 'Email-only follow-up. Fetch emails or import CSV before launch.',
                'icon' => 'mail',
                'color' => 'green',
                'node_model' => [
                    ['key' => 1, 'type' => 'action', 'channel' => 'email', 'action' => 'send_email', 'label' => 'Introduction', 'config' => ['subject' => 'Introduction', 'body' => 'Hi {{firstName}}, I wanted to reach out briefly.']],
                    ['key' => 2, 'type' => 'delay', 'value' => 3, 'time' => 'days', 'label' => 'Wait 3 days'],
                    ['key' => 3, 'type' => 'action', 'channel' => 'email', 'action' => 'send_email', 'label' => 'Follow-up', 'config' => ['subject' => 'Following up', 'body' => 'Hi {{firstName}}, just checking in on my last note.']],
                    ['key' => 99, 'type' => 'end', 'label' => 'End'],
                ],
            ],
            'whatsapp_only' => [
                'label' => 'WhatsApp Sequence',
                'description' => 'Direct WhatsApp outreach. Fetch phone from LinkedIn + verify WhatsApp, or import CSV.',
                'icon' => 'message-circle',
                'color' => 'green',
                'node_model' => [
                    ['key' => 1, 'type' => 'action', 'channel' => 'whatsapp', 'action' => 'send_message', 'label' => 'WhatsApp Intro', 'config' => ['message' => 'Hi {{firstName}}, hope you are doing well — quick question for you.']],
                    ['key' => 2, 'type' => 'delay', 'value' => 2, 'time' => 'days', 'label' => 'Wait 2 days'],
                    ['key' => 3, 'type' => 'action', 'channel' => 'whatsapp', 'action' => 'send_message', 'label' => 'WhatsApp Follow-up', 'config' => ['message' => 'Hi {{firstName}}, just bumping this in case you missed it.']],
                    ['key' => 99, 'type' => 'end', 'label' => 'End'],
                ],
            ],
            'social_dm' => [
                'label' => 'LinkedIn → Instagram DM',
                'description' => 'LinkedIn first, Instagram DM backup. Import Instagram handles via CSV, then resolve handles.',
                'icon' => 'instagram',
                'color' => 'pink',
                'node_model' => [
                    ['key' => 1, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_invite', 'label' => 'Send Invite', 'config' => ['message' => '']],
                    ['key' => 2, 'type' => 'delay', 'value' => 4, 'time' => 'days', 'label' => 'Wait 4 days'],
                    ['key' => 3, 'type' => 'condition', 'channel' => 'linkedin', 'condition' => 'invite_accepted', 'label' => 'Invite Accepted?', 'branches' => [
                        'accepted' => [
                            ['key' => 4, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_message', 'label' => 'LinkedIn Message', 'config' => ['message' => 'Thanks {{firstName}}!']],
                        ],
                        'not_accepted' => [
                            ['key' => 5, 'type' => 'action', 'channel' => 'instagram', 'action' => 'send_message', 'label' => 'Instagram DM', 'config' => ['message' => 'Hey {{firstName}}! Sent you a connect on LinkedIn — thought I would say hi here too.']],
                        ],
                    ]],
                    ['key' => 99, 'type' => 'end', 'label' => 'End'],
                ],
            ],
            'instagram_only' => [
                'label' => 'Instagram DM Sequence',
                'description' => 'Instagram-only outreach. Import @handles via CSV, then Prepare contacts to resolve messaging IDs.',
                'icon' => 'instagram',
                'color' => 'pink',
                'node_model' => [
                    ['key' => 1, 'type' => 'action', 'channel' => 'instagram', 'action' => 'send_message', 'label' => 'Instagram Intro', 'config' => ['message' => 'Hey {{firstName}}! Quick note for you.']],
                    ['key' => 2, 'type' => 'delay', 'value' => 2, 'time' => 'days', 'label' => 'Wait 2 days'],
                    ['key' => 3, 'type' => 'action', 'channel' => 'instagram', 'action' => 'send_message', 'label' => 'Instagram Follow-up', 'config' => ['message' => 'Hi {{firstName}}, bumping this in case you missed it.']],
                    ['key' => 99, 'type' => 'end', 'label' => 'End'],
                ],
            ],
            'custom' => [
                'label' => 'Custom Sequence',
                'description' => 'Build your own multichannel outreach from scratch — add only the channels you need.',
                'icon' => 'settings',
                'color' => 'slate',
                'node_model' => [
                    ['key' => 99, 'type' => 'end', 'label' => 'End'],
                ],
            ],
        ];
    }
}
