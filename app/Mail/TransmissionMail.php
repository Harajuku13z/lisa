<?php
namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class TransmissionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $sender,
        public array $payload,
        public ?string $message,
        public string $sourceDate,
    ) {}

    public function envelope(): Envelope
    {
        $human = Carbon::parse($this->sourceDate)->locale('fr_FR')->isoFormat('dddd D MMMM YYYY');
        return new Envelope(
            subject: "Transmission Lisa — {$human}",
        );
    }

    public function content(): Content
    {
        $human = Carbon::parse($this->sourceDate)->locale('fr_FR')->isoFormat('dddd D MMMM YYYY');

        return new Content(
            view: 'emails.transmission',
            with: [
                'senderName'  => $this->sender->name,
                'senderEmail' => $this->sender->email,
                'rooms'       => $this->payload['rooms'] ?? [],
                'message'     => $this->message,
                'humanDate'   => $human,
                'sourceDate'  => $this->sourceDate,
            ],
        );
    }
}
