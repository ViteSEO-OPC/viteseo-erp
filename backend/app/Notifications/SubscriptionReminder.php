<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reminds a customer's Finance Admin that their trial/subscription is about to
 * lapse, or has lapsed. Sent via the configured mailer — with MAIL_MAILER=log
 * it writes to the log until a real SMTP provider is configured.
 */
class SubscriptionReminder extends Notification
{
    use Queueable;

    public const EXPIRING = 'expiring';
    public const EXPIRED = 'expired';

    /** Where customers arrange payment — keep in sync with the frontend paywall. */
    private const BILLING_EMAIL = 'billing@viteseo.ph';

    public function __construct(
        private readonly string $kind,   // expiring | expired
        private readonly int $daysLeft,
        private readonly string $plan,
        private readonly float $monthlyPrice,
        private readonly string $orgName,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $price = '₱' . number_format($this->monthlyPrice, 2);

        if ($this->kind === self::EXPIRED) {
            return (new MailMessage())
                ->subject("Your Vite SEO Accounting access is paused — {$this->orgName}")
                ->greeting('Access paused')
                ->line("Your {$this->plan} subscription for {$this->orgName} has ended, so access is paused.")
                ->line("Settle {$price}/month to restore it right away.")
                ->line("To pay, reply to this email or message us at " . self::BILLING_EMAIL . '.')
                ->salutation('— Vite SEO Systems');
        }

        $when = $this->daysLeft <= 1 ? 'tomorrow' : "in {$this->daysLeft} days";

        return (new MailMessage())
            ->subject("Your Vite SEO Accounting {$this->plan} plan renews {$when} — {$this->orgName}")
            ->greeting('Heads up')
            ->line("Your {$this->plan} subscription for {$this->orgName} ends {$when}.")
            ->line("To keep access without interruption, arrange your {$price}/month payment before then.")
            ->line('To pay, reply to this email or message us at ' . self::BILLING_EMAIL . '.')
            ->salutation('— Vite SEO Systems');
    }
}
