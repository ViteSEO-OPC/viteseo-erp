"""Discovery provider registry.

Adding a provider (e.g. Google Places) is a two-line change here plus a new module — nothing outside
this package selects a provider. ``get_provider`` is the single place the concrete provider is chosen,
driven by configuration, so the rest of the scraper (and the entire CRM) stays provider-agnostic.
"""

from __future__ import annotations

from scraper.config import Config
from scraper.providers.base import DiscoveryProvider
from scraper.providers.openstreetmap import OpenStreetMapProvider

# Provider id -> factory. Future: "google_places": _build_google_places, etc.
_PROVIDERS = {"openstreetmap": "_build_openstreetmap"}


def get_provider(config: Config) -> DiscoveryProvider:
    name = config.discovery_provider
    if name == "openstreetmap":
        return OpenStreetMapProvider(
            overpass_url=config.overpass_url,
            nominatim_url=config.nominatim_url,
            user_agent=config.user_agent,
            timeout=config.request_timeout,
        )
    raise ValueError(
        f"Unknown discovery provider '{name}'. Available: {', '.join(sorted(_PROVIDERS))}. "
        f"Google Places and other providers are planned; add them here — no other code changes."
    )
