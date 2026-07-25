<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CallReminderProspectMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $prospectName,
        public readonly string $hostName,
        public readonly string $message,
        public readonly ?string $scheduledLabel = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Reminder: your call with {$this->hostName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.call-reminder-prospect',
            with: [
                'prospectName' => $this->prospectName,
                'hostName' => $this->hostName,
                'message' => $this->message,
                'scheduledLabel' => $this->scheduledLabel,
            ],
        );
    }
}
