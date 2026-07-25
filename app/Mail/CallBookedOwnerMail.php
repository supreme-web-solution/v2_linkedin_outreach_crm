<?php

namespace App\Mail;

use App\Models\User;
use App\Models\V2Call;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CallBookedOwnerMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly User $owner,
        public readonly V2Call $call,
        public readonly string $prospectEmail,
    ) {
    }

    public function envelope(): Envelope
    {
        $prospect = trim((string) ($this->call->prospect_name ?? 'Someone'));

        return new Envelope(
            subject: "{$prospect} booked a call with you",
        );
    }

    public function content(): Content
    {
        $timezone = config('app.timezone', 'UTC');
        $scheduledAt = $this->call->scheduled_call_at?->timezone($timezone);

        return new Content(
            markdown: 'mail.call-booked-owner',
            with: [
                'ownerName' => $this->owner->name,
                'prospectName' => trim((string) ($this->call->prospect_name ?? 'A prospect')) ?: 'A prospect',
                'prospectEmail' => $this->prospectEmail,
                'prospectHeadline' => trim((string) ($this->call->prospect_headline ?? '')),
                'scheduledLabel' => $scheduledAt?->format('l, F j, Y \a\t g:i A T') ?? 'TBD',
                'callUrl' => url('/calls/'.$this->call->id),
            ],
        );
    }
}
