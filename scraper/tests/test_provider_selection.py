"""Provider selection, and the regression guarantee that OSM is untouched by the AI path existing.

The promise this file exists to keep: **adding OpenAI changed nothing about running OSM.** Not the
default, not the behaviour, not even which modules get imported. A reviewer should be able to read
these and conclude that an environment which never sets an OpenAI variable is running exactly the
code it ran before.
"""

import sys

import pytest

from scraper.config import Config
from scraper.providers import canonical_provider, get_provider
from scraper.providers.openstreetmap import OpenStreetMapProvider


def config(**overrides) -> Config:
    base = dict(
        discovery_provider="openstreetmap",
        crm_base_url="http://localhost:8001/api/v1/crm",
        crm_token="",
        overpass_url="https://overpass-api.de/api/interpreter",
        nominatim_url="https://nominatim.openstreetmap.org",
        user_agent="test",
        request_timeout=60.0,
        openai_api_key="",
        openai_model="gpt-4o-mini",
        openai_base_url="https://api.openai.com/v1",
        openai_timeout=30.0,
    )
    return Config(**{**base, **overrides})


# --- the default stays the default ----------------------------------------------


def test_the_default_provider_is_openstreetmap():
    provider = get_provider(config())

    assert isinstance(provider, OpenStreetMapProvider)
    assert provider.name == "openstreetmap"


def test_openstreetmap_needs_no_openai_configuration_at_all():
    # The safe path must work in an environment that has never heard of OpenAI.
    provider = get_provider(config(openai_api_key="", openai_model="", openai_base_url=""))

    assert isinstance(provider, OpenStreetMapProvider)


def test_selecting_osm_never_imports_the_openai_module():
    # STRUCTURAL, not incidental. The registry imports each provider inside its own branch, so a
    # broken or missing optional provider cannot affect the default path even by import side effect.
    sys.modules.pop("scraper.providers.openai_discovery", None)

    get_provider(config(discovery_provider="openstreetmap"))

    assert "scraper.providers.openai_discovery" not in sys.modules


def test_osm_is_the_ergonomic_alias_for_the_canonical_id():
    assert canonical_provider("osm") == "openstreetmap"
    assert canonical_provider("OSM") == "openstreetmap"
    # The canonical id is what lands in `leads.source`; an alias must never become provenance.
    assert get_provider(config(discovery_provider="osm")).name == "openstreetmap"


# --- selecting openai -----------------------------------------------------------


def test_selecting_openai_returns_the_openai_provider():
    from scraper.providers.openai_discovery import OpenAiDiscoveryProvider

    provider = get_provider(config(discovery_provider="openai", openai_api_key="k"))

    assert isinstance(provider, OpenAiDiscoveryProvider)
    assert provider.name == "openai"


def test_openai_without_a_key_fails_loudly_at_selection():
    with pytest.raises(ValueError, match="OPENAI_API_KEY"):
        get_provider(config(discovery_provider="openai", openai_api_key=""))


def test_the_openai_provider_resolves_through_a_real_osm_provider():
    # Identity always comes from OSM, even on the AI path.
    provider = get_provider(config(discovery_provider="openai", openai_api_key="k"))

    assert isinstance(provider._osm, OpenStreetMapProvider)


def test_an_unknown_provider_still_raises():
    with pytest.raises(ValueError, match="Unknown discovery provider"):
        get_provider(config(discovery_provider="google_places"))


# --- the OSM contract is unchanged ----------------------------------------------


def test_find_by_name_is_additive_and_discover_is_untouched():
    # `discover()` keeps its exact signature; `find_by_name` is a new, separate entry point. If
    # someone routes discover() through the name resolver, the recorded-fixture tests in
    # test_providers.py fail — this asserts the two remain distinct methods.
    assert hasattr(OpenStreetMapProvider, "discover")
    assert hasattr(OpenStreetMapProvider, "find_by_name")
    assert OpenStreetMapProvider.discover is not OpenStreetMapProvider.find_by_name


def test_osm_provider_ids_keep_their_historical_unprefixed_form():
    # Re-prefixing OSM keys (`node/123` -> `osm/node/123`) would make every already-imported lead
    # look new and duplicate the entire pool. The namespacing convention applies to FUTURE providers
    # that establish identity some other way, never retroactively to this one.
    from scraper.providers.openstreetmap import parse_element

    business = parse_element({"type": "node", "id": 123, "lat": 1.0, "lon": 2.0, "tags": {"name": "X"}})

    assert business.provider_id == "node/123"
    assert not business.provider_id.startswith("osm/")
