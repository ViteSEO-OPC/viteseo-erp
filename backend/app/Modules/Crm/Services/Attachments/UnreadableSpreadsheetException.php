<?php

namespace App\Modules\Crm\Services\Attachments;

use RuntimeException;

/**
 * A workbook the preview reader will not or cannot read.
 *
 * Carries only text that is SAFE TO SHOW A USER. Every underlying failure — a corrupt archive, a
 * password-protected workbook, an unsupported BIFF version, a PDF renamed to .xlsx, a parser fatal —
 * is collapsed into one of the constants below by {@see SpreadsheetPreviewReader}, so the endpoint
 * can echo `getMessage()` straight to the client without leaking a file path, a class name, a stack
 * frame or a library version. The original throwable is kept as `$previous` for the log only.
 */
class UnreadableSpreadsheetException extends RuntimeException
{
    /** Every unreadable-workbook cause. Deliberately one message: the distinctions are not the user's. */
    public const UNREADABLE = 'This spreadsheet could not be opened for preview. It may be corrupt, password-protected, or saved in an unsupported format — download it to open it in Excel.';

    /** The workbook is structurally fine but too large to scan inside a web request. */
    public const TOO_LARGE = 'This spreadsheet is too large to preview. Download it to open it in Excel.';
}
