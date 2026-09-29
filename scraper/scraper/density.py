"""``density``: where a niche's businesses are, nationally, before a nationwide run decides its order.

Prints one JSON object on stdout — ``{"category", "country", "selectors", "count", "points", "ids"}``
(``ids[i]`` is the OSM key of ``points[i]``, the same ``type/id`` a discovered business carries) — and
nothing else there, so the CRM can read it. Every diagnostic goes to stderr. It asks the CRM nothing
and writes nothing anywhere: the probe informs ORDER, and only the per-cell sweep ever finds leads.

Exit codes are the contract the CRM falls back on, and every non-zero one means "keep today's order":

    0  points printed
    2  bad arguments (e.g. a country that is not ISO 3166-1 alpha-2)
    3  the niche has no OSM tag mapping — the same words the sweep itself accepts, no new list
    4  Overpass could not answer (retries exhausted, transport error, unreadable body)
"""

from __future__ import annotations

import json
import sys
from typing import Any

import httpx

from scraper.providers import build_openstreetmap
from scraper.providers.openstreetmap import category_selectors

EXIT_OK = 0
EXIT_BAD_ARGUMENTS = 2
EXIT_UNSUPPORTED = 3
EXIT_PROBE_FAILED = 4


def run_density(config: Any, category: str, country: str) -> int:
    try:
        selectors = category_selectors(category)
    except ValueError as exc:
        print(f"density: unsupported niche — {exc}", file=sys.stderr)
        return EXIT_UNSUPPORTED

    provider = build_openstreetmap(config)
    try:
        places = provider.national_places(selectors, country_code=country)
    except ValueError as exc:
        print(f"density: {exc}", file=sys.stderr)
        return EXIT_BAD_ARGUMENTS
    except (RuntimeError, httpx.HTTPError) as exc:
        # RuntimeError is `_post_with_retry` giving up on a busy server; the class name is enough.
        print(f"density: Overpass could not answer ({type(exc).__name__}).", file=sys.stderr)
        return EXIT_PROBE_FAILED
    finally:
        provider.close()

    json.dump(
        {
            "category": category,
            "country": country.upper(),
            "selectors": [f"{k}={v}" for k, v in selectors],
            "count": len(places),
            "points": [[lat, lon] for lat, lon, _ in places],
            # Parallel to `points`. Lets a volume-mode run count, per cell, the businesses its CRM
            # does not hold yet; every other reader ignores it.
            "ids": [key for _, _, key in places],
        },
        sys.stdout,
        separators=(",", ":"),
    )
    sys.stdout.write("\n")
    return EXIT_OK
