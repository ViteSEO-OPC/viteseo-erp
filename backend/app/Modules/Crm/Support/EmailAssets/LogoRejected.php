<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support\EmailAssets;

use InvalidArgumentException;

/** An uploaded logo, or its crop, that cannot be accepted. The message is safe to show the admin. */
final class LogoRejected extends InvalidArgumentException {}
