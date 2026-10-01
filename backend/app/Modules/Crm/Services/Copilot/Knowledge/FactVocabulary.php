<?php

namespace App\Modules\Crm\Services\Copilot\Knowledge;

/**
 * The words an answer uses to NAME a supplied figure — what ties a number to its metric.
 *
 * Keyed by fact path: a scalar fact by its name (`overdue`), a breakdown bucket by its key
 * (`leads_by_status.new` → `new`). A path with no entry derives its words from its own name (so a new
 * KPI label is covered without an edit here). `null` = a generic figure with no naming word of its
 * own (the lead total, "436 leads").
 *
 * English stems carry a leading `\b` so "low" never matches inside "follow"; Tagalog affixes
 * ("nakapagpadala", "bagong") are matched inside the word.
 */
final class FactVocabulary
{
    /** Scalar facts and breakdown buckets, by name or bucket key. */
    private const WORDS = [
        // Lead totals: generic.
        'total_leads' => null,

        // Status buckets and the KPI cards that repeat them.
        'new' => '\bnew\b|bago',
        'interested' => '\binterest|interesado',
        'interested_leads' => '\binterest|interesado',
        'awaiting-reply' => '\bawaiting\b|\breply\b|naghihintay',
        'call-back-req' => '\bcall[\s-]?backs?\b|tawagan ulit|balikan',
        'declined' => '\bdeclin|tumanggi|tinanggihan',
        'other' => '\bother\b|\biba\b',

        // Priority buckets.
        'high' => '\bhigh\b|\bhighest(?=[\s-]*priorit)|mataas',
        'high_priority_leads' => '\bhigh\b|\bhighest(?=[\s-]*priorit)|mataas',
        'medium' => '\bmedium\b|katamtaman',
        'low' => '\blow\b|mababa',

        // Website buckets.
        'has-website' => '\b(?:has|have|with)(?: a)? website|may website',
        'no-website' => '\bno[\s-]website|\bwithout(?: a)? website|walang website',
        'needs-redesign' => '\bredesign',
        'website-unknown' => '\bunknown\b|\bnot (?:yet )?checked\b|hindi pa alam',
        'website-unreachable' => '\bunreachable\b|\bdown\b|hindi ma-?access',

        // Follow-up work.
        'follow_ups_due' => '\bfollow[\s-]?ups?\b|\bdue\b',
        'overdue' => '\boverdue\b|\blate\b|lagpas|lampas|atrasado|delayed',
        'due_today' => '\btoday\b|ngayong araw|\bngayon\b',
        'upcoming' => '\bupcoming\b|\bcoming\b|darating|susunod',
        'completed_outreach_emails' => '\boutreach\b|\bemails?\b|\bsent\b|\boutbound\b|padala',

        // One authorised lead's profile (Phase 2 Slice 2). Same words as the caller's totals on purpose:
        // which one a figure is gets decided by the lead-subject rule in CopilotAnswerValidator.
        'lead_score' => '\bscores?\b|\bpoints?\b|puntos|iskor',
        'last_activity' => '\blast\b|\bactivity\b|\bactive\b|huling|aktibidad',
        'lead_overdue_follow_ups' => '\boverdue\b|\blate\b|lagpas|lampas|atrasado|delayed',
        'lead_follow_ups_due_today' => '\btoday\b|ngayong araw|\bngayon\b',
        'lead_upcoming_follow_ups' => '\bupcoming\b|\bcoming\b|darating|susunod',

        // Performance.
        'assigned_leads' => '\bassign|naka-?assign',
        'active_leads' => '\bactive\b|aktibo',
        'engaged_leads' => '\bengag',
        'converted_leads' => '\bconver|\bwon\b|nanalo|naisara',
        'overall_revenue' => '\brevenue\b|\bkita\b|\bearn|\bsales\b',
        'estimated_pipeline_value' => '\bpipeline\b|\bvalue\b|halaga',
        'completed_follow_ups' => '\bcompleted\b|\bdone\b|natapos|tapos na',
        'missed_follow_ups' => '\bmiss|hindi nagawa|nalampasan',
        'missed_meetings' => '\bmiss|\bmeetings?\b|pulong',
        'missed_calls' => '\bmiss|\bcalls?\b|tawag',
        'conversion_rate' => '\bconver',
        'target' => '\btarget|\bgoal|layunin',
        'progress' => '\bprogress|usad',

        // Scoring signals (reference figures — used to hold a signed figure to its signal's sign).
        'no_website' => '\bno[\s-]website|\bwithout(?: a)? website|walang website',
        'has_website' => '\b(?:has|have|having|with)(?: an?)?(?: existing| adequate)? website|may website|\bexisting website|\bwebsite exists',
        'needs_redesign' => '\bredesign',
        'target_location' => '\btarget (?:service )?(?:location|area)|\bservice area',
        'contact_person' => '\bcontact(?: person)?\b|\bnamed contact',
        'interested_reply' => '\binterest',
        'call_completed' => '\bcalls? completed|\bcompleted calls?|\bcall (?:took place|happened|was held)',
        'call_scheduled' => '\bcalls? scheduled|\bscheduled calls?|\bcall (?:is )?booked',
        'email_opened' => '\bemails? opened|\bopened|\breplied',
        'awaiting_reply' => '\bawaiting',
        'recent_activity' => '\brecent(?:ly)? (?:activity|worked)|\bwithin the last',
        'no_activity' => '\bno activity|\binactiv|\buntouched|walang galaw',
        'invalid_email' => '\binvalid|\bbounced|\bundeliverable',
        'fit_cap' => '\bfit\b',
        'engagement_cap' => '\bengagement\b',
    ];

    /** The breakdown buckets — the lead GROUPS an answer can name. */
    private const GROUPS = [
        'new', 'interested', 'awaiting-reply', 'call-back-req', 'declined', 'other',
        'high', 'medium', 'low',
        'has-website', 'no-website', 'needs-redesign', 'website-unknown', 'website-unreachable',
    ];

    /** Words too generic to name a figure when a pattern is derived from a label. */
    private const STOP = ['leads', 'lead', 'rate', 'total', 'with', 'from', 'days', 'count', 'your', 'this'];

    /** A pattern (without delimiters) matching any named lead group — a status, priority or website bucket. */
    public static function groupPattern(): string
    {
        return implode('|', array_map(static fn (string $g): string => (string) self::WORDS[$g], self::GROUPS));
    }

    /** The naming pattern (without delimiters) for a fact path, or null for a generic figure. */
    public static function pattern(string $path): ?string
    {
        $leaf = str_contains($path, '.') ? substr($path, strrpos($path, '.') + 1) : $path;
        if (array_key_exists($leaf, self::WORDS)) {
            return self::WORDS[$leaf];
        }

        preg_match_all('/\p{L}{4,}/u', mb_strtolower($leaf), $m);
        $words = array_values(array_diff(array_unique($m[0]), self::STOP));

        return $words === [] ? null : '\b(?:'.implode('|', array_map(static fn (string $w): string => preg_quote($w, '/'), $words)).')';
    }
}
