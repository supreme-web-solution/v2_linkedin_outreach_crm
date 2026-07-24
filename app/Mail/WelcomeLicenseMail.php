<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeLicenseMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $plainPassword,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your '.config('app.name').' account is ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>Hi '.$this->user->name.',</p>'
                .'<p>Your purchase has been activated. Log in with:</p>'
                .'<p><strong>Email:</strong> '.$this->user->email.'<br>'
                .'<strong>Password:</strong> '.$this->plainPassword.'</p>'
                .'<p>Please change your password after first login.</p>',
        );
    }
}
