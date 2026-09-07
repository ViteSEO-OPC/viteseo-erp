<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

enum JournalStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
}
