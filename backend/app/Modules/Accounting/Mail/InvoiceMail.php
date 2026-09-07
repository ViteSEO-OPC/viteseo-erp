<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The invoice emailed to the customer ("Send to customer"). Sends from the
 * global MAIL_FROM identity — Accounting has no module sender block, and the
 * seller the customer sees is the org identity in the body, not the From
 * header. Scalars only (no models) so it is safe to queue.
 */
class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{description:?string, quantity:float, unit_price:float, line_total:float}>  $lines
     */
    public function __construct(
        public string $orgName,
        public ?string $orgTin,
        public ?string $orgAddress,
        public string $customerName,
        public string $invoiceNo,
        public string $issueDate,
        public ?string $dueDate,
        public array $lines,
        public float $subtotal,
        public float $taxRate,
        public float $taxAmount,
        public float $total,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Invoice {$this->invoiceNo} from {$this->orgName}");
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->buildHtml());
    }

    private function buildHtml(): string
    {
        $rows = '';
        foreach ($this->lines as $line) {
            $rows .= '<tr>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e4e9e8">' . e($line['description'] ?? '—') . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e4e9e8;text-align:right">' . number_format($line['quantity'], 2) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e4e9e8;text-align:right">' . number_format($line['unit_price'], 2) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e4e9e8;text-align:right">' . number_format($line['line_total'], 2) . '</td>'
                . '</tr>';
        }

        return '<div style="font-family:system-ui,Arial,sans-serif;color:#16201e;max-width:560px">'
            . '<h2 style="color:#12756f;margin-bottom:2px">' . e($this->orgName) . '</h2>'
            . ($this->orgAddress !== null && $this->orgAddress !== '' ? '<p style="color:#5b6b67;margin:0;font-size:13px">' . e($this->orgAddress) . '</p>' : '')
            . ($this->orgTin !== null && $this->orgTin !== '' ? '<p style="color:#5b6b67;margin:0;font-size:13px">TIN: ' . e($this->orgTin) . '</p>' : '')
            . '<p style="margin-top:16px">Hi ' . e($this->customerName) . ', here is your invoice <strong>' . e($this->invoiceNo) . '</strong>.</p>'
            . '<p style="color:#5b6b67;font-size:13px;margin:4px 0 12px">Issued ' . e($this->issueDate)
            . ($this->dueDate !== null && $this->dueDate !== '' ? ' · Due ' . e($this->dueDate) : '')
            . '</p>'
            . '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:14px">'
            . '<tr style="color:#5b6b67;font-size:12px;text-transform:uppercase">'
            . '<th style="padding:6px 8px;text-align:left;border-bottom:2px solid #e4e9e8">Description</th>'
            . '<th style="padding:6px 8px;text-align:right;border-bottom:2px solid #e4e9e8">Qty</th>'
            . '<th style="padding:6px 8px;text-align:right;border-bottom:2px solid #e4e9e8">Unit price</th>'
            . '<th style="padding:6px 8px;text-align:right;border-bottom:2px solid #e4e9e8">Amount</th>'
            . '</tr>'
            . $rows
            . '</table>'
            . '<table cellpadding="0" cellspacing="0" style="margin:12px 0 0 auto;font-size:14px">'
            . '<tr><td style="padding:2px 12px 2px 0;color:#5b6b67">Subtotal</td><td style="text-align:right">₱' . number_format($this->subtotal, 2) . '</td></tr>'
            . '<tr><td style="padding:2px 12px 2px 0;color:#5b6b67">VAT (' . number_format($this->taxRate, 2) . '%)</td><td style="text-align:right">₱' . number_format($this->taxAmount, 2) . '</td></tr>'
            . '<tr><td style="padding:4px 12px 0 0"><strong>Total due</strong></td><td style="text-align:right"><strong>₱' . number_format($this->total, 2) . '</strong></td></tr>'
            . '</table>'
            . '<p style="color:#5b6b67;font-size:13px;margin-top:20px">Thank you for your business.</p>'
            . '</div>';
    }
}
