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
                'description' => 'Invite, wait for accept, then message. Works with any LinkedIn list.',
                'icon' => 'users',
                'color' => 'blue',
                'node_model' => [
                    ['key' => 1, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_invite', 'label' => 'Send Invite', 'config' => ['message' => '']],
                    ['key' => 2, 'type' => 'delay', 'value' => 2, 'time' => 'days', 'label' => 'Wait 2 days'],
                    ['key' => 3, 'type' => 'condition', 'channel' => 'linkedin', 'condition' => 'invite_accepted', 'label' => 'Invite Accepted?', 'branches' => [
                        'accepted' => [
                            ['key' => 4, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_message', 'label' => 'Send Message', 'config' => ['message' => '', 'personalize_before_send' => true, 'placeholder' => 'Written after research. Not a shared template.']],
                            ['key' => 5, 'type' => 'delay', 'value' => 3, 'time' => 'days', 'label' => 'Wait 3 days'],
                            ['key' => 6, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_message', 'label' => 'Follow-up Message', 'config' => ['message' => '', 'personalize_before_send' => true, 'placeholder' => 'Personalized follow-up. Not a shared check-in template.']],
                        ],
                        'not_accepted' => [],
                    ]],
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
                            ['key' => 4, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_message', 'label' => 'Send Message', 'config' => ['message' => '', 'personalize_before_send' => true, 'placeholder' => 'Personalized first message after accept. Not a shared template.']],
                        ],
                        'not_accepted' => [
                            ['key' => 5, 'type' => 'action', 'channel' => 'email', 'action' => 'send_email', 'label' => 'Send Email', 'config' => ['subject' => '', 'body' => '', 'personalize_before_send' => true, 'placeholder' => 'Personalized email when LinkedIn invite was not accepted.']],
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
                            ['key' => 4, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_message', 'label' => 'LinkedIn Message', 'config' => ['message' => '', 'personalize_before_send' => true, 'placeholder' => 'Personalized LinkedIn message after accept.']],
                        ],
                        'not_accepted' => [
                            ['key' => 5, 'type' => 'action', 'channel' => 'whatsapp', 'action' => 'send_message', 'label' => 'WhatsApp Message', 'config' => ['message' => '', 'personalize_before_send' => true, 'placeholder' => 'Personalized WhatsApp when LinkedIn invite was not accepted.']],
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
                            ['key' => 4, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_message', 'label' => 'LinkedIn Message', 'config' => ['message' => '', 'personalize_before_send' => true, 'placeholder' => 'Personalized LinkedIn message after accept.']],
                        ],
                        'not_accepted' => [
                            ['key' => 5, 'type' => 'action', 'channel' => 'email', 'action' => 'send_email', 'label' => 'Email Follow-up', 'config' => ['subject' => '', 'body' => '', 'personalize_before_send' => true, 'placeholder' => 'Personalized email follow-up. Not a shared check-in template.']],
                            ['key' => 6, 'type' => 'delay', 'value' => 2, 'time' => 'days', 'label' => 'Wait 2 days'],
                            ['key' => 7, 'type' => 'action', 'channel' => 'whatsapp', 'action' => 'send_message', 'label' => 'WhatsApp Touch', 'config' => ['message' => '', 'personalize_before_send' => true, 'placeholder' => 'Personalized WhatsApp follow-up. Not a shared check-in template.']],
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
                    ['key' => 1, 'type' => 'action', 'channel' => 'email', 'action' => 'send_email', 'label' => 'Introduction', 'config' => ['subject' => '', 'body' => '', 'personalize_before_send' => true, 'placeholder' => 'Personalized introduction. Not a shared template.']],
                    ['key' => 2, 'type' => 'delay', 'value' => 3, 'time' => 'days', 'label' => 'Wait 3 days'],
                    ['key' => 3, 'type' => 'action', 'channel' => 'email', 'action' => 'send_email', 'label' => 'Follow-up', 'config' => ['subject' => '', 'body' => '', 'personalize_before_send' => true, 'placeholder' => 'Personalized follow-up. Not a shared check-in template.']],
                    ['key' => 99, 'type' => 'end', 'label' => 'End'],
                ],
            ],
            'whatsapp_only' => [
                'label' => 'WhatsApp Sequence',
                'description' => 'Direct WhatsApp outreach. Fetch phone from LinkedIn + verify WhatsApp, or import CSV.',
                'icon' => 'message-circle',
                'color' => 'green',
                'node_model' => [
                    ['key' => 1, 'type' => 'action', 'channel' => 'whatsapp', 'action' => 'send_message', 'label' => 'WhatsApp Intro', 'config' => ['message' => '', 'personalize_before_send' => true, 'placeholder' => 'Personalized WhatsApp intro. Not a shared template.']],
                    ['key' => 2, 'type' => 'delay', 'value' => 2, 'time' => 'days', 'label' => 'Wait 2 days'],
                    ['key' => 3, 'type' => 'action', 'channel' => 'whatsapp', 'action' => 'send_message', 'label' => 'WhatsApp Follow-up', 'config' => ['message' => '', 'personalize_before_send' => true, 'placeholder' => 'Personalized WhatsApp follow-up. Not a shared check-in template.']],
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
            'twitter_only' => [
                'label' => 'Twitter / X DM Sequence',
                'description' => 'Twitter/X DMs. Import @handles via save_contacts or CSV, then prepare contacts before volume sends.',
                'icon' => 'twitter',
                'color' => 'sky',
                'node_model' => [
                    ['key' => 1, 'type' => 'action', 'channel' => 'twitter', 'action' => 'send_message', 'label' => 'Twitter Intro', 'config' => ['message' => 'Hey {{firstName}} — quick note for you.']],
                    ['key' => 2, 'type' => 'delay', 'value' => 2, 'time' => 'days', 'label' => 'Wait 2 days'],
                    ['key' => 3, 'type' => 'action', 'channel' => 'twitter', 'action' => 'send_message', 'label' => 'Twitter Follow-up', 'config' => ['message' => 'Hi {{firstName}}, bumping this in case you missed it.']],
                    ['key' => 99, 'type' => 'end', 'label' => 'End'],
                ],
            ],
            'telegram_only' => [
                'label' => 'Telegram Sequence',
                'description' => 'Direct Telegram outreach. Import phone or @handles via CSV, then Prepare contacts before launch.',
                'icon' => 'send',
                'color' => 'sky',
                'node_model' => [
                    ['key' => 1, 'type' => 'action', 'channel' => 'telegram', 'action' => 'send_message', 'label' => 'Telegram Intro', 'config' => ['message' => 'Hi {{firstName}}, quick note for you.']],
                    ['key' => 2, 'type' => 'delay', 'value' => 2, 'time' => 'days', 'label' => 'Wait 2 days'],
                    ['key' => 3, 'type' => 'action', 'channel' => 'telegram', 'action' => 'send_message', 'label' => 'Telegram Follow-up', 'config' => ['message' => 'Hi {{firstName}}, bumping this in case you missed it.']],
                    ['key' => 99, 'type' => 'end', 'label' => 'End'],
                ],
            ],
            'linkedin_telegram' => [
                'label' => 'LinkedIn → Telegram',
                'description' => 'LinkedIn invite first, Telegram follow-up. Fetch phone or Telegram handle before launch.',
                'icon' => 'send',
                'color' => 'sky',
                'node_model' => [
                    ['key' => 1, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_invite', 'label' => 'Send Invite', 'config' => ['message' => '']],
                    ['key' => 2, 'type' => 'delay', 'value' => 2, 'time' => 'days', 'label' => 'Wait 2 days'],
                    ['key' => 3, 'type' => 'condition', 'channel' => 'linkedin', 'condition' => 'invite_accepted', 'label' => 'Invite Accepted?', 'branches' => [
                        'accepted' => [
                            ['key' => 4, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_message', 'label' => 'LinkedIn Message', 'config' => ['message' => 'Thanks {{firstName}} — quick question for you.']],
                        ],
                        'not_accepted' => [
                            ['key' => 5, 'type' => 'action', 'channel' => 'telegram', 'action' => 'send_message', 'label' => 'Telegram Message', 'config' => ['message' => 'Hi {{firstName}}, I reached out on LinkedIn — happy to chat here if easier.']],
                        ],
                    ]],
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
