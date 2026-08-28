import json
from pathlib import Path

import pytest

FIXTURES = Path(__file__).parent / "fixtures"


def _load(name: str) -> dict:
    return json.loads((FIXTURES / name).read_text(encoding="utf-8"))


@pytest.fixture
def overpass_element() -> dict:
    """A real, recorded Overpass element (Starbucks cafe, Makati)."""
    return _load("overpass_element.json")


@pytest.fixture
def nominatim_reverse() -> dict:
    """A real, recorded Nominatim reverse-geocode response (Makati)."""
    return _load("nominatim_reverse.json")


@pytest.fixture(autouse=True)
def isolate_run_metrics(tmp_path, monkeypatch):
    """Keep test runs out of the real `.discovery-metrics.jsonl`.

    Discovery telemetry is evidence for the Python-vs-OpenAI comparison, so a test run appending
    synthetic rows to it would quietly corrupt that evidence. Autouse: any test that reaches
    `run_discovery` writes a metrics line, and it should never be the real one.
    """
    from scraper import run_metrics

    monkeypatch.setenv(run_metrics.METRICS_FILE_ENV, str(tmp_path / "metrics.jsonl"))


@pytest.fixture(autouse=True)
def isolate_crm_provider_lookup(monkeypatch):
    """Keep the CRM provider lookup off the network in tests.

    `resolve_provider_choice` asks the CRM which provider to use, which in a unit test would mean a
    real HTTP call to whatever `CRM_BASE_URL` happens to point at — slow, flaky, and dependent on a
    developer's local stack. Defaulting to None makes every test behave as "CRM unavailable", which
    is precisely the safe path: precedence falls through to DISCOVERY_PROVIDER and then to
    OpenStreetMap, so the pre-existing tests keep exercising exactly the behaviour they always did.

    A test that wants the CRM to answer overrides this by patching `fetch_crm_provider` itself.
    """
    monkeypatch.setattr("scraper.providers.fetch_crm_provider", lambda config: None)
