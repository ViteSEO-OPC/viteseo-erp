<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Mail\CandidateComposedMail;
use App\Modules\Hr\Models\Candidate;
use App\Modules\Hr\Models\CandidateEmail;
use App\Modules\Hr\Support\HtmlSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Composing and sending candidate emails. */
class CandidateEmailService
{
    /** Attachments live on the private disk, reachable only through a signed URL. */
    private const DISK = 'local';

    private const ATTACHMENT_DIR = 'candidate-emails';

    public function __construct(private readonly AuditService $audit) {}

    /**
     * What the compose modal opens with for this candidate and email type.
     *
     * @return array{to: string, cc: array<int, string>, bcc: array<int, string>, reply_to: ?string, subject: string, body_html: string, previous_sent_at: ?string}
     */
    public function draft(Candidate $candidate, string $type): array
    {
        $this->assertSendable($candidate, $type);

        $previous = $this->lastSent($candidate, $type);

        if ($previous) {
            return [
                'to' => $previous->to_email,
                'cc' => $previous->cc ?? [],
                'bcc' => $previous->bcc ?? [],
                'reply_to' => $previous->reply_to,
                'subject' => $previous->subject,
                'body_html' => $previous->body_html,
                'previous_sent_at' => $previous->sent_at?->toIso8601String(),
            ];
        }

        $template = $this->template($candidate, $type);

        return [
            'to' => (string) $candidate->email,
            'cc' => [],
            'bcc' => [],
            'reply_to' => null,
            'subject' => $template['subject'],
            'body_html' => $template['body_html'],
            'previous_sent_at' => null,
        ];
    }

    /**
     * Send a composed email and record it.
     *
     * @param  array{type: string, to: string, cc?: array<int, string>, bcc?: array<int, string>, reply_to?: ?string, subject: string, body_html: string, deadline?: ?string}  $data
     * @param  array<int, UploadedFile>  $files
     */
    public function send(Candidate $candidate, array $data, array $files = []): CandidateEmail
    {
        $type = $data['type'];
        $this->assertSendable($candidate, $type);

        $body = HtmlSanitizer::clean($data['body_html']);
        if (HtmlSanitizer::toPlainText($body) === '') {
            // A body that is only markup — an empty editor, or a paste that sanitised away —
            // would reach the candidate as a blank message.
            throw ValidationException::withMessages([
                'body_html' => ['Write something in the message before sending.'],
            ]);
        }

        // The board badge and the candidate drawer both read the deadline off the candidate,
        // so it stays a structured field even though the modal shows it beside the body.
        if ($type === 'assessment') {
            $candidate->update([
                'assessment_deadline' => $data['deadline'] ?? null,
                'assessment_sent_at' => now(),
            ]);
        }

        $email = CandidateEmail::create([
            'candidate_id' => $candidate->id,
            'type' => $type,
            'to_email' => $data['to'],
            'cc' => $this->addresses($data['cc'] ?? []),
            'bcc' => $this->addresses($data['bcc'] ?? []),
            'reply_to' => $data['reply_to'] ?? null,
            'subject' => $data['subject'],
            'body_html' => $body,
            'attachments' => $this->storeAttachments($files),
            'status' => CandidateEmail::STATUS_SENT,
            'sent_at' => now(),
            'sent_by_user_id' => Auth::id(),
        ]);

        try {
            Mail::to($email->to_email)->send(new CandidateComposedMail($email));
        } catch (\Throwable $e) {
            $email->update([
                'status' => CandidateEmail::STATUS_FAILED,
                'error' => $e->getMessage(),
                'sent_at' => null,
            ]);

            report($e);

            $this->audit->log(
                'updated',
                'Recruitment',
                "Failed to send {$this->label($type)} email to {$candidate->name}",
                $candidate,
            );

            throw ValidationException::withMessages([
                'to' => ['The email could not be sent. Check the address and try again.'],
            ]);
        }

        $sent = collect($candidate->emails_sent ?? [])->push($type)->unique()->values()->all();
        $candidate->update(['emails_sent' => $sent]);

        $this->audit->log(
            'updated',
            'Recruitment',
            "Sent {$this->label($type)} email to {$candidate->name} ({$email->to_email})",
            $candidate,
        );

        return $email;
    }

    /**
     * Every email sent to this candidate, newest first — including the failed attempts, which
     * are the ones worth seeing.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CandidateEmail>
     */
    public function history(Candidate $candidate)
    {
        return CandidateEmail::query()
            ->where('candidate_id', $candidate->id)
            ->with('sentBy:id,name')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The type must match where the candidate actually is. Without this an offer email could be
     * sent to someone still in screening — the compose modal made the body editable, not the
     * pipeline meaningless.
     */
    private function assertSendable(Candidate $candidate, string $type): void
    {
        if (! in_array($type, RecruitmentService::EMAIL_TYPES, true)) {
            throw ValidationException::withMessages(['type' => ['Unknown email type.']]);
        }

        if (RecruitmentService::emailTypeForStage($candidate->stage) !== $type) {
            throw ValidationException::withMessages([
                'type' => ["This email doesn't match the candidate's current stage."],
            ]);
        }
    }

    private function lastSent(Candidate $candidate, string $type): ?CandidateEmail
    {
        return CandidateEmail::query()
            ->where('candidate_id', $candidate->id)
            ->where('type', $type)
            ->where('status', CandidateEmail::STATUS_SENT)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The stage templates, filled in for this candidate. These are the same words the board
     * used to send verbatim, now editable before they go.
     *
     * @return array{subject: string, body_html: string}
     */
    private function template(Candidate $candidate, string $type): array
    {
        $candidate->loadMissing('requisition');

        $name = e($candidate->name);
        $role = e($candidate->requisition?->title ?? 'the role');
        $signature = '<p>Best regards,<br>The Hiring Team</p>';

        $subject = match ($type) {
            'online_interview' => "Online Interview Invitation — {$candidate->requisition?->title}",
            'final_interview' => "Final Interview Invitation — {$candidate->requisition?->title}",
            'assessment' => "Assessment — {$candidate->requisition?->title}",
            'offer' => "Job Offer — {$candidate->requisition?->title}",
            'rejection' => "Update on your application — {$candidate->requisition?->title}",
            default => "Update — {$candidate->requisition?->title}",
        };

        $body = match ($type) {
            'online_interview' => "<p>Thank you for applying for the <strong>{$role}</strong> role. "
                . "We'd like to invite you to an <strong>initial online interview</strong>. "
                . 'Our team will follow up shortly with a schedule and video link.</p>',
            'final_interview' => "<p>Great news — you've advanced to the <strong>final interview</strong> "
                . "for the <strong>{$role}</strong> role. This round is with the hiring team; "
                . "we'll share the details and timing separately.</p>",
            'assessment' => $this->assessmentTemplate($candidate, $role),
            'offer' => "<p>Congratulations! We're delighted to extend an <strong>offer</strong> for the "
                . "<strong>{$role}</strong> role. Our HR team will reach out with your offer letter, "
                . 'compensation details, and onboarding next steps.</p>',
            'rejection' => "<p>Thank you for taking the time to apply for the <strong>{$role}</strong> role "
                . 'and for sharing your background with us. After careful consideration, we won\'t be '
                . 'moving forward at this time. We genuinely appreciate your interest and encourage you '
                . 'to apply for future openings that match your experience.</p>',
            default => "<p>There's an update regarding your application for <strong>{$role}</strong>.</p>",
        };

        return [
            'subject' => $subject,
            'body_html' => "<p>Hi {$name},</p>{$body}{$signature}",
        ];
    }

    /**
     * The assessment template is a scaffold rather than a message: what an assessment is
     * differs per role and per test, so it leaves HR a visible gap to fill in the editor
     * instead of pretending to know.
     */
    private function assessmentTemplate(Candidate $candidate, string $role): string
    {
        $body = "<p>Thank you for your interest in the <strong>{$role}</strong> role. "
            . 'The next step is a short <strong>assessment</strong>.</p>';

        // A previous assessment left its details on the candidate; reuse them rather than
        // making HR retype a link they already sent.
        if ($candidate->assessment_instructions) {
            $body .= '<p>' . nl2br(e($candidate->assessment_instructions)) . '</p>';
        } else {
            $body .= '<p>[What the assessment covers, how long it takes, and anything to prepare.]</p>';
        }

        if ($candidate->assessment_link) {
            $link = e($candidate->assessment_link);
            $body .= "<p><a href=\"{$link}\">{$link}</a></p>";
        }

        return $body;
    }

    /**
     * Attachments go to the private disk under a random name. The original filename is kept
     * only as metadata — it is what the candidate sees on the attachment, and letting it
     * decide the stored path is how a crafted name escapes the directory.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array{path: string, name: string, size: int, mime: string}>|null
     */
    private function storeAttachments(array $files): ?array
    {
        $stored = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store(self::ATTACHMENT_DIR, self::DISK);

            $stored[] = [
                'path' => $path,
                'name' => $file->getClientOriginalName(),
                'size' => Storage::disk(self::DISK)->size($path),
                'mime' => $file->getClientMimeType(),
            ];
        }

        return $stored ?: null;
    }

    /**
     * Normalise a recipient list: trimmed, de-duplicated case-insensitively, blanks dropped.
     *
     * @param  array<int, string>  $list
     * @return array<int, string>|null
     */
    private function addresses(array $list): ?array
    {
        $clean = collect($list)
            ->map(fn ($address) => trim((string) $address))
            ->filter()
            ->unique(fn (string $address) => strtolower($address))
            ->values()
            ->all();

        return $clean ?: null;
    }

    private function label(string $type): string
    {
        return match ($type) {
            'online_interview' => 'online interview',
            'final_interview' => 'final interview',
            'assessment' => 'assessment',
            'offer' => 'offer',
            'rejection' => 'rejection',
            default => $type,
        };
    }
}
