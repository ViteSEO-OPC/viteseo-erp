<?php

namespace App\Modules\Crm\Services\Import;

use App\Modules\Crm\Services\Attachments\DocumentPreviewException;
use RuntimeException;

/**
 * A lead-import commit refused because another import is committing for the same organization
 * (CEO audit crm-06, the concurrent-duplicate part).
 *
 * Same contract as {@see DocumentPreviewException}: the message is
 * a fixed constant the endpoint can show to the user as-is, and the exception carries its HTTP status.
 * It is a 409, not a 422: nothing is wrong with the file, and the same commit succeeds once the other
 * import has finished.
 */
class LeadImportInProgressException extends RuntimeException
{
    public const MESSAGE = 'Another lead import is already in progress for your organization. Please try again in a moment.';

    public function __construct(public readonly int $status = 409)
    {
        parent::__construct(self::MESSAGE);
    }
}
