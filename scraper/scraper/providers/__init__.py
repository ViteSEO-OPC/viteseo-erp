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

import sys
from dataclasses import dataclass
from typing import Optional

from scraper.config import Config, fetch_crm_provider
from scraper.providers.base import DiscoveryProvider
from scraper.providers.openstreetmap import OpenStreetMapProvider

#: Provider id -> what it is. Future: "google_places", and a Capability B provider that establishes
#: identity by website/Wikidata rather than by an OSM node.
_PROVIDERS = {
    "openstreetmap": "OpenStreetMap Overpass/Nominatim (default, no API key)",
    "openai": "OpenAI proposes candidates; OSM resolves them deterministically",
}

#: The permanent baseline, and the end of every fallback chain. Named so the fallback path reads as
#: a decision rather than a bare literal.
DEFAULT_PROVIDER = "openstreetmap"

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


@dataclass(frozen=True)
class ProviderChoice:
    """Which provider will run, and WHERE that decision came from.

    `source` is carried so a run can say why it did what it did. "OpenStreetMap ran" is not the same
    fact as "OpenStreetMap ran because the CRM is unreachable", and an operator debugging an AI run
    that produced no tokens needs the difference.
    """

    provider: str
    source: str  # "cli" | "crm" | "env" | "default"
    reason: Optional[str] = None


def config_for(config: Config, choice: "ProviderChoice") -> Config:
    """The same config with the resolved provider applied — how a choice becomes a buildable config.

    Kept as a helper so `get_provider(config)` keeps its original one-argument shape: the seam that
    every existing caller and test already relies on does not change just because selection grew a
    new input.
    """
    if choice.provider == config.discovery_provider:
        return config
    return Config(**{**config.__dict__, "discovery_provider": choice.provider})


def resolve_provider_choice(config: Config, override: Optional[str] = None) -> ProviderChoice:
    """Apply the precedence: --provider -> CRM setting -> DISCOVERY_PROVIDER env -> openstreetmap.

    THE CLI FLAG WINS ABSOLUTELY. It is an explicit human instruction for one run — ops and debugging
    depend on being able to force a provider without touching stored configuration, which is why the
    env/CLI mechanism is kept rather than replaced by the UI.

    THE CRM IS THE PRODUCTION LAYER, and it answers with the EFFECTIVE provider. A provider an admin
    selected but has not credentialed arrives here already resolved to `openstreetmap` with a reason,
    so this function needs no fallback logic of its own and cannot disagree with the settings page.

    A CRM THAT CANNOT BE REACHED DEGRADES TO THE LOCAL CONFIGURATION, which ends at OpenStreetMap.
    Falling back to the safe baseline is right; doing it silently would not be, so it is announced and
    the choice's `source` records it for the run metrics.
    """
    if override:
        return ProviderChoice(canonical_provider(override), source="cli")

    crm = fetch_crm_provider(config)
    if crm is not None:
        if crm.is_fallback:
            print(
                f"CRM: {crm.configured_provider} is selected but {crm.effective_provider} will run "
                f"({crm.reason}).",
                file=sys.stderr,
            )
        return ProviderChoice(
            canonical_provider(crm.effective_provider), source="crm", reason=crm.reason
        )

    env_provider = (config.discovery_provider or "").strip()
    if env_provider:
        return ProviderChoice(canonical_provider(env_provider), source="env")

    return ProviderChoice(DEFAULT_PROVIDER, source="default")


def get_provider(config: Config) -> DiscoveryProvider:
    name = canonical_provider(config.discovery_provider)

    if name == "openstreetmap":
        return build_openstreetmap(config)

    if name == "openai":
        # Imported HERE, not at module scope, so selecting OSM never loads this path.
        from scraper.providers.openai_discovery import OpenAiDiscoveryProvider

        return OpenAiDiscoveryProvider(
            # No API key. The CRM holds the credential and makes the model call, so this process
            # never receives, stores or logs one — it asks the CRM for names over the same
            # authenticated channel it already uses to ingest leads.
            crm_base_url=config.crm_base_url,
            crm_token=config.crm_token,
            timeout=config.openai_timeout,
            # Identity always comes from OSM, even on the AI path — the model proposes names only.
            osm=build_openstreetmap(config),
        )

    raise ValueError(
        f"Unknown discovery provider '{config.discovery_provider}'. "
        f"Available: {', '.join(sorted(_PROVIDERS))} (alias: osm). "
        f"Google Places and other providers are planned; add them here — no other code changes."
    )
