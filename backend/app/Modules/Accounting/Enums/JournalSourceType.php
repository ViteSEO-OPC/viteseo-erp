<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

/**
 * Where a journal entry came from. `manual` = keyed by a person; `payroll` = auto
 * generated from an HRIS payroll run (Slice 3); `inventory` = auto-generated from
 * a period of Inventory stock movements (receipts/COGS/adjustments); `booking` =
 * auto-generated from a period of Booking payments (revenue received); `reversal`
 * = auto-generated to reverse another entry (its source_id points at the original).
 */
enum JournalSourceType: string
{
    case Manual = 'manual';
    case Payroll = 'payroll';
    case Inventory = 'inventory';
    case Booking = 'booking';
    case Reversal = 'reversal';
}
