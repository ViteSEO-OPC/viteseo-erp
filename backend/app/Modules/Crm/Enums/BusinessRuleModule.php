<?php

declare(strict_types=1);

namespace App\Modules\Crm\Enums;

/**
 * The sub-module a Business Rule version configures (Implementation Design §1.0). Only `lead_scoring` is
 * enabled in v1; `kpi` and `ranking` are reserved for the Coming Soon modules and no rows are created for
 * them until those modules are approved. This enum only names the modules — it never decides which is
 * active.
 */
enum BusinessRuleModule: string
{
    case LEAD_SCORING = 'lead_scoring';
    case KPI = 'kpi';         // reserved — Coming Soon
    case RANKING = 'ranking'; // reserved — Coming Soon

    public function label(): string
    {
        return match ($this) {
            self::LEAD_SCORING => 'Lead Scoring',
            self::KPI => 'KPI',
            self::RANKING => 'Ranking',
        };
    }
}
