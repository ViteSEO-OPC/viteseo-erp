"""The discovery target SOURCE is pluggable — the seam targets.py always documented.

Pinned here:

  - **The file source stays the default**, so every existing run is unchanged.
  - **A remote source returns the same document**, envelope or not — one schema, one validator.
  - **An unreachable remote source falls back to the file and SAYS SO.** A run that skips a night is
    worse than one using slightly stale targets, but a silent switch would hide a broken registry.
  - **No credential is ever echoed** by a source describing itself.
  - **targets.py validates identically** whichever source produced the document.

No test touches the network.
"""

import json

import httpx
import pytest

from scraper.target_sources import (
    FileTargetSource,
    HttpTargetSource,
    TargetSourceError,
    load_document,
    resolve_source,
)
from scraper.targets import TargetsError, load_targets

DOCUMENT = {
    "defaults": {"limit": 10, "targets_per_run": 2},
    "targets": [{"area": "Anywhere", "categories": ["cafe"], "group": "one"}],
}

TOKEN = "test-crm-token-not-a-real-credential"


def write(tmp_path, document, name="discovery-targets.json"):
    path = tmp_path / name
    path.write_text(json.dumps(document), encoding="utf-8")
    return path


class FakeClient:
    """Stands in for httpx.Client as a context manager."""

    def __init__(self, response=None, raises=None):
        self._response = response
        self._raises = raises
        self.requests = []

    def __call__(self, *args, **kwargs):
        return self

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False

    def get(self, url, headers=None):
        self.requests.append((url, headers or {}))
        if self._raises:
            raise self._raises
        return self._response


def response(payload, status=200, url="http://crm.test/targets"):
    return httpx.Response(status, json=payload, request=httpx.Request("GET", url))


# --- the file source: the default ------------------------------------------------------------------


def test_the_file_source_returns_the_document(tmp_path):
    source = FileTargetSource(write(tmp_path, DOCUMENT))

    assert source.load() == DOCUMENT


def test_a_missing_file_is_a_source_error(tmp_path):
    with pytest.raises(TargetSourceError, match="not found"):
        FileTargetSource(tmp_path / "absent.json").load()


def test_malformed_json_is_a_source_error(tmp_path):
    path = tmp_path / "bad.json"
    path.write_text("{not json", encoding="utf-8")

    with pytest.raises(TargetSourceError, match="not valid JSON"):
        FileTargetSource(path).load()


# --- the http source -------------------------------------------------------------------------------


def test_an_http_source_accepts_a_bare_document(monkeypatch):
    client = FakeClient(response(DOCUMENT))
    monkeypatch.setattr(httpx, "Client", client)

    assert HttpTargetSource(url="http://crm.test/targets").load() == DOCUMENT


def test_an_http_source_unwraps_the_crm_envelope(monkeypatch):
    # The CRM's standard {"data": ...} shape, so the same source works against a managed endpoint and
    # a plain static file without a flag saying which.
    client = FakeClient(response({"data": DOCUMENT}))
    monkeypatch.setattr(httpx, "Client", client)

    assert HttpTargetSource(url="http://crm.test/targets").load() == DOCUMENT


def test_an_http_source_sends_the_bearer_token_but_never_describes_it(monkeypatch):
    client = FakeClient(response(DOCUMENT))
    monkeypatch.setattr(httpx, "Client", client)
    source = HttpTargetSource(url="http://crm.test/targets", token=TOKEN)

    source.load()

    _, headers = client.requests[0]
    assert headers["Authorization"] == f"Bearer {TOKEN}"
    # A source labels itself in logs; a label that quoted the credential would put it in every line.
    assert TOKEN not in source.describe()


def test_an_http_error_status_is_a_source_error_without_the_body(monkeypatch):
    # The status only: a body from an authenticated endpoint can quote the request that carried a key.
    monkeypatch.setattr(httpx, "Client", FakeClient(response({"secret": TOKEN}, status=500)))

    with pytest.raises(TargetSourceError) as exc:
        HttpTargetSource(url="http://crm.test/targets", token=TOKEN).load()

    assert TOKEN not in str(exc.value)
    assert "500" in str(exc.value)


def test_an_unreachable_url_is_a_source_error(monkeypatch):
    monkeypatch.setattr(httpx, "Client", FakeClient(raises=httpx.ConnectError("down")))

    with pytest.raises(TargetSourceError, match="unreachable"):
        HttpTargetSource(url="http://crm.test/targets").load()


# --- choosing a source -----------------------------------------------------------------------------


def test_an_explicit_path_beats_a_configured_url(tmp_path):
    path = write(tmp_path, DOCUMENT)

    source = resolve_source(path=path, url="http://crm.test/targets", fallback_path=path)

    # An operator naming a file for one run is an instruction, not a preference — the same rule that
    # makes --provider beat the CRM's stored setting.
    assert isinstance(source, FileTargetSource)


def test_a_configured_url_is_used_when_no_path_is_given(tmp_path):
    source = resolve_source(url="http://crm.test/targets", fallback_path=tmp_path / "t.json")

    assert isinstance(source, HttpTargetSource)


def test_the_file_is_the_source_when_nothing_is_configured(tmp_path):
    path = write(tmp_path, DOCUMENT)

    assert isinstance(resolve_source(fallback_path=path), FileTargetSource)


# --- fallback --------------------------------------------------------------------------------------


def test_an_unavailable_source_falls_back_to_the_file_and_announces_it(tmp_path, monkeypatch, capsys):
    monkeypatch.setattr(httpx, "Client", FakeClient(raises=httpx.ConnectError("down")))
    fallback = FileTargetSource(write(tmp_path, DOCUMENT))

    document = load_document(HttpTargetSource(url="http://crm.test/targets"), fallback=fallback)

    assert document == DOCUMENT
    # Announced, never silent: a registry that stopped answering must be visible in the run's output
    # rather than discovered weeks later as a coverage gap.
    assert "falling back" in capsys.readouterr().err


def test_without_a_fallback_the_failure_propagates(monkeypatch):
    monkeypatch.setattr(httpx, "Client", FakeClient(raises=httpx.ConnectError("down")))

    with pytest.raises(TargetSourceError):
        load_document(HttpTargetSource(url="http://crm.test/targets"))


# --- one validator, whatever the origin -------------------------------------------------------------


def test_targets_parse_identically_from_a_remote_source(monkeypatch):
    monkeypatch.setattr(httpx, "Client", FakeClient(response({"data": DOCUMENT})))

    targets = load_targets(source=HttpTargetSource(url="http://crm.test/targets"))

    assert [(t.area, t.category, t.limit, t.group) for t in targets] == [
        ("Anywhere", "cafe", 10, "one")
    ]


def test_a_remote_document_is_validated_exactly_like_a_file(monkeypatch):
    # Two parsers would be two vocabularies, and the remote one would drift. There is only one.
    monkeypatch.setattr(httpx, "Client", FakeClient(response({"targets": []})))

    with pytest.raises(TargetsError, match="non-empty 'targets' array"):
        load_targets(source=HttpTargetSource(url="http://crm.test/targets"))


def test_a_source_failure_surfaces_as_a_targets_error(monkeypatch):
    # Callers already catch TargetsError; a source failure must not escape as a different type.
    monkeypatch.setattr(httpx, "Client", FakeClient(raises=httpx.ConnectError("down")))

    with pytest.raises(TargetsError):
        load_targets(source=HttpTargetSource(url="http://crm.test/targets"))
