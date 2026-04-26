<?php
namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ColleagueInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $sender, public string $recipientEmail) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->sender->name} vous invite sur Lisa",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.colleague_invite',
            with: [
                'senderName'  => $this->sender->name,
                'senderEmail' => $this->sender->email,
                'recipient'   => $this->recipientEmail,
            ],
        );
    }
}
