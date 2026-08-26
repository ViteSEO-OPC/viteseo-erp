"""Write-safety + configuration tests (pure, no network).

Every CRM mutation in this tool is opt-in. These tests pin that contract so it cannot regress
silently:

  discover  → submits ``dry_run=True`` unless ``--commit``
  audit     → issues ZERO PUTs unless ``--commit``

Nothing here touches the network: the discovery provider and both HTTP clients are replaced with
fakes that record calls, so a regression that tried to talk to a real CRM would fail the assertions
rather than reach one.
"""

from __future__ import annotations

import pytest

from scraper import cli
from scraper.config import Config
from scraper.models import NormalizedBusiness
from scraper.website_intelligence.pipeline import SubmitAuditPipeline


# ── Fakes ───────────────────────────────────────────────────────────────────────────────────────


class FakeProvider:
    """Stands in for the OSM provider — returns nothing, so no network and no normalization."""

    name = "fakeprovider"

    def discover(self, *, area: str, category: str, limit: int) -> list:
        return []

    def close(self) -> None:  # pragma: no cover - trivial
        pass


class RecordingIngestClient:
    """Captures how ``ingest`` was called instead of performing HTTP."""

    calls: list[dict] = []

    def __init__(self, *, base_url: str, token: str, user_agent: str) -> None:
        self.base_url = base_url

    def ingest(self, businesses, *, dry_run: bool) -> dict:
        RecordingIngestClient.calls.append({"dry_run": dry_run, "count": len(businesses)})
        return {"summary": {"total": len(businesses), "valid": 0, "duplicates": 0, "updated": 0},
                "importedCount": 0}

    def close(self) -> None:
        pass


class RecordingHttpClient:
    """Fails loudly if any HTTP verb is used; records the ones that were attempted."""

    def __init__(self) -> None:
        self.puts: list[tuple[str, dict]] = []

    def put(self, url, json=None, **kwargs):  # noqa: A002 - mirrors httpx's signature
        self.puts.append((url, json))
        return _FakeResponse()

    def close(self) -> None:
        pass


class _FakeResponse:
    def raise_for_status(self) -> None:
        pass

    def json(self) -> dict:
        return {"websiteStatus": "has-website"}


class _Spider:
    """Minimal stand-in for a Scrapy spider (the pipeline only uses ``logger``)."""

    class logger:  # noqa: N801 - matches Scrapy's attribute name
        @staticmethod
        def warning(*args, **kwargs) -> None:
            pass


def _pipeline(commit: bool) -> SubmitAuditPipeline:
    p = SubmitAuditPipeline(
        base_url="http://localhost:8001/api/v1/crm",
        token="test-token-not-real",
        user_agent="test",
        commit=commit,
    )
    p._client = RecordingHttpClient()  # never perform real HTTP
    return p


@pytest.fixture(autouse=True)
def _reset_calls():
    RecordingIngestClient.calls = []
    yield
    RecordingIngestClient.calls = []


def _patch_discover(monkeypatch, *, token: str = "test-token-not-real") -> None:
    monkeypatch.setattr(cli, "get_provider", lambda config: FakeProvider())
    monkeypatch.setattr(cli, "CrmIngestClient", RecordingIngestClient)
    monkeypatch.setenv("CRM_TOKEN", token)


# ── A. discover without --commit performs no CRM write ──────────────────────────────────────────


def test_discover_without_commit_sends_dry_run(monkeypatch):
    """The ingest call must carry dry_run=True — the CRM then validates and persists nothing."""
    _patch_discover(monkeypatch)
    # One business so the ingest call is actually reached (an empty batch short-circuits).
    monkeypatch.setattr(
        cli, "normalize", lambda raw, source: NormalizedBusiness(business_name="X", source=source)
    )
    monkeypatch.setattr(
        FakeProvider, "discover", lambda self, *, area, category, limit: [object()]
    )

    rc = cli.main(["discover", "--area", "Nowhere", "--category", "cafe"])

    assert rc == 0
    assert RecordingIngestClient.calls == [{"dry_run": True, "count": 1}]


# ── B. discover --commit enables the existing write path ────────────────────────────────────────


def test_discover_with_commit_sends_dry_run_false(monkeypatch):
    _patch_discover(monkeypatch)
    monkeypatch.setattr(
        cli, "normalize", lambda raw, source: NormalizedBusiness(business_name="X", source=source)
    )
    monkeypatch.setattr(
        FakeProvider, "discover", lambda self, *, area, category, limit: [object()]
    )

    rc = cli.main(["discover", "--area", "Nowhere", "--category", "cafe", "--commit"])

    assert rc == 0
    assert RecordingIngestClient.calls == [{"dry_run": False, "count": 1}]


# ── C. audit without --commit performs ZERO PUT mutations ───────────────────────────────────────


def test_audit_pipeline_without_commit_issues_no_put():
    """The single most important guarantee: a dry-run audit must not mutate the CRM at all."""
    p = _pipeline(commit=False)

    item = {"lead_id": "42", "website_reachable": True, "https_enabled": True}
    returned = p.process_item(dict(item), _Spider())

    assert p._client.puts == []  # nothing was sent
    assert p.submitted == 0
    assert p.skipped == 1
    assert returned["lead_id"] == "42"  # the item still flows through for reporting


def test_audit_pipeline_defaults_to_no_commit():
    """An unset CRM_COMMIT setting must never be read as permission to write."""
    p = SubmitAuditPipeline(base_url="http://x/api/v1/crm", token="t", user_agent="ua")
    p._client = RecordingHttpClient()

    p.process_item({"lead_id": "7"}, _Spider())

    assert p.commit is False
    assert p._client.puts == []


# ── D. audit --commit enables the enrichment update path ────────────────────────────────────────


def test_audit_pipeline_with_commit_puts_to_enrichment_endpoint():
    p = _pipeline(commit=True)

    p.process_item({"lead_id": "42", "website_reachable": True}, _Spider())

    assert len(p._client.puts) == 1
    url, payload = p._client.puts[0]
    assert url == "http://localhost:8001/api/v1/crm/leads/42/website-enrichment"
    assert "lead_id" not in payload  # the id is the path, never the body
    assert payload["website_reachable"] is True
    assert p.submitted == 1


# ── E. configuration default is the new unified CRM base URL ────────────────────────────────────


def test_config_default_base_url_targets_unified_crm(monkeypatch):
    """With nothing in the environment, the built-in default must be the unified /api/v1/crm base."""
    # Bypass the local .env so this asserts the CODE default, not a developer's machine.
    monkeypatch.setattr("scraper.config._load_dotenv", lambda: None)
    monkeypatch.delenv("CRM_BASE_URL", raising=False)

    assert Config.load().crm_base_url == "http://localhost:8001/api/v1/crm"


def test_config_env_overrides_default(monkeypatch):
    """Environment wins — staging/production need no source change."""
    monkeypatch.setattr("scraper.config._load_dotenv", lambda: None)
    monkeypatch.setenv("CRM_BASE_URL", "https://crm.example.test/api/v1/crm")

    assert Config.load().crm_base_url == "https://crm.example.test/api/v1/crm"


class FailedProvider:
    """A provider whose upstream call did not succeed — zero candidates, but NOT a successful run."""

    name = "openai"

    def __init__(self, status: str = "auth_failed", error: str = "http_401") -> None:
        self._status = status
        self._error = error

    def discover(self, *, area, category, limit):
        return []

    def stats(self):
        return {"execution_status": self._status, "error_reason": self._error}


class EmptyButHealthyProvider:
    """A provider that ran fine and legitimately found nothing."""

    name = "openai"

    def discover(self, *, area, category, limit):
        return []

    def stats(self):
        return {"execution_status": "ok", "error_reason": None}


def test_a_failed_provider_execution_is_not_reported_as_a_successful_empty_run(monkeypatch, tmp_path):
    """The correction this phase exists for.

    A rejected credential and a town with no pharmacies both yield zero candidates. If the CLI treated
    them the same, a broken integration would log "Nothing discovered" night after night and nobody
    would find out. A failed execution exits non-zero so a scheduler alarms.
    """
    monkeypatch.setattr(cli, "get_provider", lambda config: FailedProvider())
    monkeypatch.setattr(cli, "CrmIngestClient", RecordingIngestClient)
    RecordingIngestClient.calls = []

    monkeypatch.setenv("CRM_TOKEN", "test-token-not-real")

    rc = cli.main(["discover", "--provider", "openai", "--area", "A", "--category", "cafe", "--commit"])

    assert rc != 0
    # And nothing was sent to the CRM — a failed discovery must not reach the ingest at all.
    assert RecordingIngestClient.calls == []


def test_a_healthy_provider_that_found_nothing_is_still_a_successful_run(monkeypatch):
    """The mirror image: an honest empty result must not be dressed up as a failure either."""
    monkeypatch.setattr(cli, "get_provider", lambda config: EmptyButHealthyProvider())
    monkeypatch.setattr(cli, "CrmIngestClient", RecordingIngestClient)
    RecordingIngestClient.calls = []

    monkeypatch.setenv("CRM_TOKEN", "test-token-not-real")

    rc = cli.main(["discover", "--provider", "openai", "--area", "A", "--category", "cafe", "--commit"])

    assert rc == 0
    assert RecordingIngestClient.calls == []


def test_run_metrics_records_the_provider_that_actually_ran(monkeypatch, tmp_path):
    """`provider` is what ran; `provider_selected`/`provider_source` say what was asked for and why.

    A reader who can only see one provider name cannot tell a healthy OSM run from an AI run that
    fell back — which is exactly the ambiguity the comparison must not inherit.
    """
    from scraper import run_metrics

    metrics_file = tmp_path / "metrics.jsonl"
    monkeypatch.setenv(run_metrics.METRICS_FILE_ENV, str(metrics_file))
    monkeypatch.setattr(cli, "get_provider", lambda config: FailedProvider())
    monkeypatch.setattr(cli, "CrmIngestClient", RecordingIngestClient)

    monkeypatch.setenv("CRM_TOKEN", "test-token-not-real")

    cli.main(["discover", "--provider", "openai", "--area", "A", "--category", "cafe"])

    records = run_metrics.load(metrics_file)
    assert len(records) == 1
    record = records[0]
    assert record["provider"] == "openai"
    assert record["provider_selected"] == "openai"
    assert record["provider_source"] == "cli"
    assert record["provider_execution_status"] == "auth_failed"
    assert record["error_reason"] == "http_401"
    assert record["succeeded"] is False
