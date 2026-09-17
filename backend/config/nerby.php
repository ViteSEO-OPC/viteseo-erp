<?php

/**
 * Nearby discovery — MARKET SETTINGS, not business logic.
 *
 * Everything here is a deployment fact rather than a product decision, and it lives in config for
 * exactly one reason: no place name, niche or search phrase may be pinned inside application logic.
 * A discovery product that opens a second market must be a configuration change, never a code change.
 *
 * There is deliberately no area list, no city list and no category list here. Which areas get swept
 * is runtime data (the discovery target source); which categories exist is the provider's own
 * vocabulary. Putting either here would recreate the hardcoded geography this file exists to prevent.
 */
return [
    /*
     * Region/language biasing for structured place providers (Google Places `regionCode` /
     * `languageCode`). A market setting: it shapes how a provider interprets an ambiguous query,
     * and it is NOT a target area — the area always arrives per request.
     */
    'region_code' => env('NERBY_REGION_CODE', 'PH'),
    'language_code' => env('NERBY_LANGUAGE_CODE', 'en'),

    /*
     * The credential probe's query.
     *
     * A connection test only has to prove that a key authenticates and that the endpoint answers, so
     * this is a deliberately GENERIC word carrying no place and no product category. It used to be a
     * real demo search ("pharmacy in <city>"), which quietly turned a test input into a runtime
     * constant — the thing a discovery system must never do, because the sample then reads like an
     * intended default to everyone who finds it later.
     */
    'probe_query' => env('NERBY_PROBE_QUERY', 'business'),
];
