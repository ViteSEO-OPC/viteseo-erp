"""Discovery provider registry.

``get_provider`` is the single place the concrete provider is chosen, driven by configuration, so the
rest of the scraper (and the entire CRM) stays provider-agnostic. Adding a provider is an entry here
plus a new module — nothing outside this package selects a provider.

``openstreetmap`` is the DEFAULT and the safe baseline. It needs no API key, no vendor and no
billing, and every other provider is measured against it.

IMPORTS ARE LAZY, PER BRANCH, AND THAT IS LOAD-BEARING. A provider's module is imported only when it
is actually selected, so a broken, half-configured or missing optional provider cannot affect the
default path — not even by import side effect. There is a test asserting the OpenAI module is absent
from ``sys.modules`` after an OSM run.
"""

from __future__ import annotations

from scraper.config import Config
from scraper.providers.base import DiscoveryProvider
from scraper.providers.openstreetmap import OpenStreetMapProvider

#: Provider id -> what it is. Future: "google_places", and a Capability B provider that establishes
#: identity by website/Wikidata rather than by an OSM node.
_PROVIDERS = {
    "openstreetmap": "OpenStreetMap Overpass/Nominatim (default, no API key)",
    "openai": "OpenAI proposes candidates; OSM resolves them deterministically",
}

#: `--provider osm` is the ergonomic spelling of the default; the canonical id stays the full name
#: because it is stored on every discovered lead as `source` and changing it would rewrite provenance.
_ALIASES = {"osm": "openstreetmap"}


def canonical_provider(name: str) -> str:
    """Resolve an alias to the canonical provider id."""
    key = (name or "").strip().lower()
    return _ALIASES.get(key, key)


def build_openstreetmap(config: Config) -> OpenStreetMapProvider:
    """The OSM provider, built from config. Shared with the OpenAI provider, which resolves through it."""
    return OpenStreetMapProvider(
        overpass_url=config.overpass_url,
        nominatim_url=config.nominatim_url,
        user_agent=config.user_agent,
        timeout=config.request_timeout,
    )


def get_provider(config: Config) -> DiscoveryProvider:
    name = canonical_provider(config.discovery_provider)

    if name == "openstreetmap":
        return build_openstreetmap(config)

    if name == "openai":
        # Imported HERE, not at module scope, so selecting OSM never loads this path.
        from scraper.providers.openai_discovery import OpenAiDiscoveryProvider

        return OpenAiDiscoveryProvider(
            api_key=config.openai_api_key,
            model=config.openai_model,
            base_url=config.openai_base_url,
            timeout=config.openai_timeout,
            # Identity always comes from OSM, even on the AI path — the model proposes names only.
            osm=build_openstreetmap(config),
        )

    raise ValueError(
        f"Unknown discovery provider '{config.discovery_provider}'. "
        f"Available: {', '.join(sorted(_PROVIDERS))} (alias: osm). "
        f"Google Places and other providers are planned; add them here — no other code changes."
    )
