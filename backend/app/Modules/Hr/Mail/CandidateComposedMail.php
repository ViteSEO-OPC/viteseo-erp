<?php

namespace App\Modules\Hr\Mail;

use App\Modules\Hr\Models\CandidateEmail;
use App\Modules\Hr\Support\HtmlSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A candidate email exactly as HR composed it. */
class CandidateComposedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CandidateEmail $email) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->email->subject,
            cc: self::addresses($this->email->cc),
            bcc: self::addresses($this->email->bcc),
            replyTo: $this->email->reply_to
                ? [new Address($this->email->reply_to)]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'hr::emails.candidate',
            // Clients that refuse HTML still get the message rather than an empty body.
            text: 'hr::emails.candidate-text',
            with: [
                'bodyHtml' => $this->email->body_html,
                'bodyText' => HtmlSanitizer::toPlainText($this->email->body_html),
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return collect($this->email->attachments ?? [])
            ->map(fn (array $file) => Attachment::fromStorageDisk('local', $file['path'])
                ->as($file['name'])
                ->withMime($file['mime'] ?? 'application/octet-stream'))
            ->all();
    }

    /**
     * @param  array<int, string>|null  $list
     * @return array<int, Address>
     */
    private static function addresses(?array $list): array
    {
        return collect($list ?? [])
            ->filter()
            ->map(fn (string $address) => new Address($address))
            ->values()
            ->all();
    }
}
