<?php

namespace App\Modules\Hr\Mail;

use App\Modules\Hr\Models\Candidate;
use App\Modules\Hr\Models\Requisition;
use App\Support\Mail\Module;
use App\Support\Mail\SendsAsModule;
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
    use Queueable, SendsAsModule, SerializesModels;

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
            'assessment' => "Assessment — {$role}",
            'offer' => "Job Offer — {$role}",
            'rejection' => "Update on your application — {$role}",
            default => "Update — {$role}",
        };

        // Candidate mail goes out as HR, not as the platform-wide MAIL_FROM_ADDRESS: an
        // applicant should hear from HR even though CRM and PMS share the same relay.
        return new Envelope(
            subject: $subject,
            from: $this->moduleSender(Module::Hr)->toAddress(),
        );
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
            'assessment' => $this->assessmentBody($role),
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

    /**
     * The assessment email is the only one HR writes rather than picks: the link and
     * instructions live on the candidate, put there by the compose dialog just before
     * this renders. Either one may be absent — the service guarantees at least one.
     */
    private function assessmentBody(string $role): string
    {
        $body = "<p>Thank you for your interest in the <strong>{$role}</strong> role. "
            . 'The next step is a short <strong>assessment</strong>.</p>';

        if ($this->candidate->assessment_instructions) {
            $body .= '<p>' . nl2br(e($this->candidate->assessment_instructions)) . '</p>';
        }

        if ($this->candidate->assessment_link) {
            $link = e($this->candidate->assessment_link);
            $body .= "<p><a href=\"{$link}\">{$link}</a></p>";
        }

        if ($this->candidate->assessment_deadline) {
            $due = e($this->candidate->assessment_deadline->format('F j, Y'));
            $body .= "<p>Please complete it by <strong>{$due}</strong>.</p>";
        }

        return $body;
    }
}
