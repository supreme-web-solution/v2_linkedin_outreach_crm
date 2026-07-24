<?php

namespace App\Mail;

use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2TeamInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeamInviteMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly V2TeamInvite $invite,
        public readonly User $inviter,
        public readonly V2Organization $organization
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->inviter->name.' invited you to '.$this->organization->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.team-invite',
            with: [
                'inviterName' => $this->inviter->name,
                'organizationName' => $this->organization->name,
                'acceptUrl' => url('/team/accept/'.$this->invite->token),
                'role' => $this->invite->role,
                'expiresAt' => $this->invite->expires_at?->format('M j, Y'),
            ],
        );
    }
}
