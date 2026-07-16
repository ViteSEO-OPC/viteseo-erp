<?php

namespace App\Modules\Hr\Mail;

use App\Modules\Hr\Models\Candidate;
use App\Modules\Hr\Models\Requisition;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A stage-specific email to a candidate. One class, four templates keyed by $type:
 * online-interview invite, final-interview invite, job offer, and a courteous
 * rejection. In dev (MAIL_MAILER catcher) it lands in the local inbox.
 */
class CandidateNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Candidate $candidate,
        public Requisition $requisition,
        public string $type,
    ) {}

    public function envelope(): Envelope
    {
        $role = $this->requisition->title;

        $subject = match ($this->type) {
            'online_interview' => "Online Interview Invitation — {$role}",
            'final_interview' => "Final Interview Invitation — {$role}",
            'offer' => "Job Offer — {$role}",
            'rejection' => "Update on your application — {$role}",
            default => "Update — {$role}",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $name = e($this->candidate->name);
        $role = e($this->requisition->title);

        $body = match ($this->type) {
            'online_interview' => "<p>Thank you for applying for the <strong>{$role}</strong> role. "
                . "We'd like to invite you to an <strong>initial online interview</strong>. "
                . "Our team will follow up shortly with a schedule and video link.</p>",
            'final_interview' => "<p>Great news — you've advanced to the <strong>final interview</strong> "
                . "for the <strong>{$role}</strong> role. This round is with the hiring team; "
                . "we'll share the details and timing separately.</p>",
            'offer' => "<p>Congratulations! We're delighted to extend an <strong>offer</strong> for the "
                . "<strong>{$role}</strong> role. Our HR team will reach out with your offer letter, "
                . "compensation details, and onboarding next steps.</p>",
            'rejection' => "<p>Thank you for taking the time to apply for the <strong>{$role}</strong> role "
                . "and for sharing your background with us. After careful consideration, we won't be "
                . "moving forward at this time. We genuinely appreciate your interest and encourage you "
                . "to apply for future openings that match your experience.</p>",
            default => "<p>There's an update regarding your application for <strong>{$role}</strong>.</p>",
        };

        return new Content(
            htmlString: <<<HTML
                <p>Hi {$name},</p>
                {$body}
                <p>Best regards,<br>The Hiring Team</p>
                HTML,
        );
    }
}
