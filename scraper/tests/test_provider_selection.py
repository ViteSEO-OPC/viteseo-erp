"""Provider selection: precedence, the CRM-driven switch, and the OSM guarantee.

The promise this file keeps: **adding a Settings-driven switch changed nothing about running OSM.**
Not the default, not the behaviour, not which modules get imported, not the CLI. A reviewer should be
able to read these and conclude that an environment which never configures the CRM switch is running
exactly the code it ran before.

The precedence under test is `--provider` -> CRM setting -> `DISCOVERY_PROVIDER` env -> openstreetmap.
The CRM answers with the EFFECTIVE provider, so "OpenAI selected but not credentialed" arrives here
already resolved to OpenStreetMap — this module owns no fallback rules of its own, which is why there
are none to test.
"""

import sys

import pytest

from scraper.config import Config, CrmProviderConfig
from scraper.providers import (
    DEFAULT_PROVIDER,
    canonical_provider,
    config_for,
    get_provider,
    resolve_provider_choice,
)
from scraper.providers.openstreetmap import OpenStreetMapProvider


def config(**overrides) -> Config:
    base = dict(
        discovery_provider="openstreetmap",
        crm_base_url="http://localhost:8001/api/v1/crm",
        crm_token="test-token",
        overpass_url="https://overpass-api.de/api/interpreter",
        nominatim_url="https://nominatim.openstreetmap.org",
        user_agent="test",
        request_timeout=60.0,
        openai_timeout=30.0,
    )
    return Config(**{**base, **overrides})


def crm_says(monkeypatch, effective: str, *, configured: str | None = None, reason: str = "configured"):
    """Make the CRM answer with a given effective provider (no network)."""
    monkeypatch.setattr(
        "scraper.providers.fetch_crm_provider",
        lambda cfg: CrmProviderConfig(
            effective_provider=effective,
            configured_provider=configured or effective,
            reason=reason,
            mode="live" if effective == (configured or effective) else "preview",
            is_fallback=effective != (configured or effective),
        ),
    )


def crm_unavailable(monkeypatch):
    monkeypatch.setattr("scraper.providers.fetch_crm_provider", lambda cfg: None)


# --- the default stays the default ----------------------------------------------


def test_the_default_provider_is_openstreetmap(monkeypatch):
    crm_unavailable(monkeypatch)

    provider = get_provider(config())

    assert isinstance(provider, OpenStreetMapProvider)
    assert provider.name == "openstreetmap"


def test_openstreetmap_needs_no_ai_configuration_at_all(monkeypatch):
    # The safe path must work in an environment that has never configured a CRM switch or a model.
    crm_unavailable(monkeypatch)

    assert isinstance(get_provider(config(crm_token="")), OpenStreetMapProvider)


def test_selecting_osm_never_imports_the_openai_module(monkeypatch):
    # STRUCTURAL, not incidental. The registry imports each provider inside its own branch, so a
    # broken or missing optional provider cannot affect the default path even by import side effect.
    crm_unavailable(monkeypatch)
    sys.modules.pop("scraper.providers.openai_discovery", None)

    get_provider(config(discovery_provider="openstreetmap"))

    assert "scraper.providers.openai_discovery" not in sys.modules


def test_osm_is_the_ergonomic_alias_for_the_canonical_id(monkeypatch):
    crm_unavailable(monkeypatch)

    assert canonical_provider("osm") == "openstreetmap"
    assert canonical_provider("OSM") == "openstreetmap"
    # The canonical id is what lands in `leads.source`; an alias must never become provenance.
    assert get_provider(config(discovery_provider="osm")).name == "openstreetmap"


def test_an_unknown_provider_still_raises(monkeypatch):
    crm_unavailable(monkeypatch)

    with pytest.raises(ValueError, match="Unknown discovery provider"):
        get_provider(config(discovery_provider="google_places"))


def test_google_places_is_not_a_runtime_provider(monkeypatch):
    # It is a roadmap entry in the Settings UI and nothing else. Until it is evaluated and approved
    # it must not be selectable, from the CRM or from a flag.
    crm_unavailable(monkeypatch)

    with pytest.raises(ValueError):
        get_provider(config_for(config(), resolve_provider_choice(config(), "google_places")))


# --- precedence: cli > crm > env > default --------------------------------------


def test_an_explicit_flag_beats_the_crm(monkeypatch):
    # Ops and comparison runs depend on being able to force a provider for one run without touching
    # stored configuration — that is why the CLI mechanism is preserved rather than replaced.
    crm_says(monkeypatch, "openai")

    choice = resolve_provider_choice(config(), "osm")

    assert choice.provider == "openstreetmap"
    assert choice.source == "cli"


def test_an_explicit_flag_skips_the_crm_entirely(monkeypatch):
    called = []
    monkeypatch.setattr(
        "scraper.providers.fetch_crm_provider", lambda cfg: called.append(1) or None
    )

    resolve_provider_choice(config(), "osm")

    # Not merely "the flag wins" — the CRM is never even consulted, so a forced run cannot be slowed
    # or broken by an unreachable CRM.
    assert called == []


def test_the_crm_beats_the_env_when_no_flag_is_given(monkeypatch):
    crm_says(monkeypatch, "openai")

    choice = resolve_provider_choice(config(discovery_provider="openstreetmap"))

    assert choice.provider == "openai"
    assert choice.source == "crm"


def test_the_env_is_used_when_the_crm_cannot_be_reached(monkeypatch):
    # The operational fallback that must survive this transition.
    crm_unavailable(monkeypatch)

    choice = resolve_provider_choice(config(discovery_provider="openai"))

    assert choice.provider == "openai"
    assert choice.source == "env"


def test_everything_absent_ends_at_openstreetmap(monkeypatch):
    crm_unavailable(monkeypatch)

    choice = resolve_provider_choice(config(discovery_provider=""))

    assert choice.provider == DEFAULT_PROVIDER == "openstreetmap"
    assert choice.source == "default"


# --- the CRM resolves the fallback; this module does not ------------------------


def test_the_scraper_obeys_the_effective_provider_not_the_selected_one(monkeypatch):
    # The load-bearing decision of Phase 2c. The CRM says "openai is selected but openstreetmap will
    # run"; the scraper runs OpenStreetMap and needs no fallback logic of its own. A second copy of
    # that rule in Python would be a copy that eventually disagrees with the settings page.
    crm_says(
        monkeypatch, "openstreetmap", configured="openai", reason="provider_not_configured"
    )

    choice = resolve_provider_choice(config())

    assert choice.provider == "openstreetmap"
    assert choice.source == "crm"
    assert choice.reason == "provider_not_configured"
    assert isinstance(get_provider(config_for(config(), choice)), OpenStreetMapProvider)


@pytest.mark.parametrize(
    "reason",
    ["provider_disabled", "provider_not_configured", "unknown_provider", "default_no_configuration"],
)
def test_every_crm_fallback_reason_lands_on_openstreetmap(monkeypatch, reason):
    crm_says(monkeypatch, "openstreetmap", configured="openai", reason=reason)

    choice = resolve_provider_choice(config())

    assert choice.provider == "openstreetmap"
    assert choice.reason == reason


def test_a_crm_selected_openai_is_buildable_and_needs_no_openai_key(monkeypatch):
    from scraper.providers.ai_assisted import AiAssistedDiscoveryProvider
    from scraper.providers.openai_discovery import OpenAiDiscoveryProvider

    crm_says(monkeypatch, "openai")
    choice = resolve_provider_choice(config())

    provider = get_provider(config_for(config(), choice))

    # Selecting AI builds the COMPOSITE, not the AI provider alone — the OSM baseline is part of what
    # "the AI provider" now means, which is the whole point of it being unable to return less.
    assert isinstance(provider, AiAssistedDiscoveryProvider)
    assert isinstance(provider._ai, OpenAiDiscoveryProvider)
    # The credential lives in the CRM. This process authenticates to the CRM and nothing else.
    assert not hasattr(provider, "api_key")
    assert not hasattr(provider._ai, "api_key")
    assert isinstance(provider._osm, OpenStreetMapProvider)


def test_the_ai_composite_sweeps_and_resolves_through_one_osm_client(monkeypatch):
    # One client per run, and one owner to close it. Two would double the load this tool puts on
    # Overpass and Nominatim, which are shared community infrastructure.
    crm_says(monkeypatch, "openai")
    provider = get_provider(config_for(config(), resolve_provider_choice(config())))

    assert provider._osm is provider._ai._osm


def test_openai_without_a_crm_token_fails_loudly(monkeypatch):
    # A silent fall back to OSM would label a plain OSM run as `openai` in the metrics and corrupt the
    # comparison the switch exists to enable. Note it is the CRM token that is required now — this
    # process never holds an OpenAI key.
    crm_unavailable(monkeypatch)

    with pytest.raises(ValueError, match="CRM_TOKEN"):
        get_provider(config(discovery_provider="openai", crm_token=""))


# --- the OSM contract is unchanged ----------------------------------------------


def test_get_provider_keeps_its_single_argument_shape():
    # The seam every existing caller and test relies on. A resolved choice is applied by building a
    # config (`config_for`), not by growing this function a second parameter.
    import inspect

    assert list(inspect.signature(get_provider).parameters) == ["config"]


def test_find_by_name_is_additive_and_discover_is_untouched():
    assert hasattr(OpenStreetMapProvider, "discover")
    assert hasattr(OpenStreetMapProvider, "find_by_name")
    assert OpenStreetMapProvider.discover is not OpenStreetMapProvider.find_by_name


def test_osm_provider_ids_keep_their_historical_unprefixed_form():
    # Re-prefixing OSM keys (`node/123` -> `osm/node/123`) would make every already-imported lead look
    # new and duplicate the entire pool.
    from scraper.providers.openstreetmap import parse_element

    business = parse_element({"type": "node", "id": 123, "lat": 1.0, "lon": 2.0, "tags": {"name": "X"}})

    assert business.provider_id == "node/123"
    assert not business.provider_id.startswith("osm/")
