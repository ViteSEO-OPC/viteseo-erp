<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Calling;

use RuntimeException;

/**
 * Browser calling was offered, but can no longer be carried for this call: the organization's Twilio
 * configuration was switched off, emptied or changed between the capability check and the moment the
 * browser credential was to be signed.
 *
 * A client-facing condition, answered with the same 422 "browser calling is not available" the manual
 * workspace gets — never a server error. The BD falls back to Call on Phone exactly as if the button
 * had not been offered.
 */
final class BrowserCallingUnavailable extends RuntimeException {}
