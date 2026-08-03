<?php

namespace App\Modules\Crm\Enums;

/**
 * The metrics a performance target may be set against.
 *
 * Deliberately a closed set drawn from the surfaces that actually render a target today — the BD's
 * monthly Personal Target and the three quarterly Quarter Targets tiles. A metric is listed here
 * only when a live numerator already exists to compare it against; a target with nothing to measure
 * would render as a denominator over a blank.
 */
enum PerformanceTargetMetric: string
{
    /** Personal Target (monthly) — measured against performance-summary.convertedLeads. */
    case CONVERTED_LEADS = 'converted_leads';

    /** Quarter Targets tiles — measured against reports-analytics.followThrough.*. */
    case LEADS_CONTACTED = 'leads_contacted';
    case CONSULTATIONS = 'consultations';
    case CONFIRMED_CLIENTS = 'confirmed_clients';

    /** Human label for UI and activity descriptions. */
    public function label(): string
    {
        return match ($this) {
            self::CONVERTED_LEADS => 'Converted Leads',
            self::LEADS_CONTACTED => 'Leads Contacted',
            self::CONSULTATIONS => 'Consultations',
            self::CONFIRMED_CLIENTS => 'Confirmed Clients',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
