"""A local run must not be able to write to a deployed CRM by accident.

The hazard is specific, not theoretical: `CRM_BASE_URL` has a localhost DEFAULT but a `.env` file
silently overrides it, so a machine provisioned for a real deployment runs every command against that
deployment unless the operator remembers otherwise — and nothing on screen said so. A stray
`find-websites --commit` there does not merely add junk rows; it can mark real leads' website searches
EXHAUSTED, which is precisely what invites a real BD to confirm a business has no website. Evidence
that was never gathered would be presented as evidence that was.

These tests pin the two halves of the fix: the target is always visible, and a remote commit is
refused unless someone opted in on purpose.
"""

import scraper.cli as cli
from scraper.safety import (
    ALLOW_REMOTE_ENV,
    CrmTarget,
    describe_target,
    refuse_remote_commit,
    remote_commit_allowed,
)

LOCAL = "http://localhost:8001/api/v1/crm"
PRODUCTION = "https://system.viteseo.ph/api/v1/crm"


# --- classifying the target -----------------------------------------------------


def test_the_documented_local_target_is_recognised_as_local():
    target = describe_target(LOCAL)

    assert target.host == "localhost"
    assert target.is_local is True


def test_loopback_spellings_are_all_local():
    for url in [
        "http://127.0.0.1:8001/api/v1/crm",
        "http://localhost/api/v1/crm",
        "http://0.0.0.0:8001/api/v1/crm",
        "http://host.docker.internal:8001/api/v1/crm",
    ]:
        assert describe_target(url).is_local is True, url


def test_a_deployed_host_is_not_local():
    target = describe_target(PRODUCTION)

    assert target.host == "system.viteseo.ph"
    assert target.is_local is False


def test_a_private_lan_address_is_treated_as_remote():
    # Somebody else's box — quite possibly staging. Calling it "local" because the IP is private is
    # exactly the assumption that gets a shared environment written to from a laptop.
    for url in ["http://192.168.1.20:8001/api/v1/crm", "http://10.0.0.5/api/v1/crm"]:
        assert describe_target(url).is_local is False, url


def test_an_unparseable_target_is_treated_as_remote():
    # Fail towards refusing. A false remote costs one environment variable; a false local costs a
    # write to production.
    for url in ["", "not a url", "://broken"]:
        assert describe_target(url).is_local is False, repr(url)


# --- the refusal ----------------------------------------------------------------


def test_a_remote_commit_is_refused_by_default():
    refusal = refuse_remote_commit(describe_target(PRODUCTION), commit=True, env={})

    assert refusal is not None
    assert "system.viteseo.ph" in refusal
    # The message has to carry the way out, or the operator's next move is to guess.
    assert ALLOW_REMOTE_ENV in refusal


def test_a_remote_commit_is_allowed_with_the_explicit_override():
    refusal = refuse_remote_commit(
        describe_target(PRODUCTION), commit=True, env={ALLOW_REMOTE_ENV: "1"}
    )

    assert refusal is None


def test_a_local_commit_needs_no_override():
    assert refuse_remote_commit(describe_target(LOCAL), commit=True, env={}) is None


def test_a_dry_run_against_a_remote_target_is_not_refused():
    # Reads stay open on purpose: pointing a dry run at another environment is recoverable, and the
    # banner is what makes a wrong setting visible before a write happens.
    assert refuse_remote_commit(describe_target(PRODUCTION), commit=False, env={}) is None


def test_only_deliberate_values_enable_the_override():
    for value in ["1", "true", "TRUE", "yes", "on"]:
        assert remote_commit_allowed({ALLOW_REMOTE_ENV: value}) is True, value
    for value in ["", "0", "false", "no", "maybe"]:
        assert remote_commit_allowed({ALLOW_REMOTE_ENV: value}) is False, value


def test_an_absent_override_is_not_an_enabled_one():
    assert remote_commit_allowed({}) is False


# --- the CLI actually enforces it -----------------------------------------------


def _point_at(monkeypatch, url: str) -> None:
    """Point Config at `url` without touching the operator's real .env."""
    monkeypatch.setattr(cli.Config, "load", classmethod(lambda cls: _config(url)))


def _config(url: str):
    from scraper.config import Config

    return Config(
        discovery_provider="openstreetmap",
        crm_base_url=url,
        crm_token="test-token",
        overpass_url="https://overpass.invalid",
        nominatim_url="https://nominatim.invalid",
        user_agent="test",
        request_timeout=1.0,
    )


def test_the_cli_refuses_a_remote_commit_before_doing_any_work(monkeypatch, capsys):
    _point_at(monkeypatch, PRODUCTION)
    monkeypatch.delenv(ALLOW_REMOTE_ENV, raising=False)
    called = []
    monkeypatch.setattr(cli, "run_website_discovery", lambda *a, **k: called.append(1) or 0)

    exit_code = cli.main(["find-websites", "--commit"])

    assert exit_code == 2
    # The point is that nothing ran — refusing after the queue has been read and candidates
    # submitted would be no protection at all.
    assert called == []
    assert "REFUSING" in capsys.readouterr().err


def test_the_cli_allows_a_remote_commit_once_the_override_is_set(monkeypatch):
    _point_at(monkeypatch, PRODUCTION)
    monkeypatch.setenv(ALLOW_REMOTE_ENV, "1")
    called = []
    monkeypatch.setattr(cli, "run_website_discovery", lambda *a, **k: called.append(1) or 0)

    assert cli.main(["find-websites", "--commit"]) == 0
    assert called == [1]


def test_the_cli_allows_a_local_commit_without_any_override(monkeypatch):
    _point_at(monkeypatch, LOCAL)
    monkeypatch.delenv(ALLOW_REMOTE_ENV, raising=False)
    called = []
    monkeypatch.setattr(cli, "run_website_discovery", lambda *a, **k: called.append(1) or 0)

    assert cli.main(["find-websites", "--commit"]) == 0
    assert called == [1]


def test_a_remote_dry_run_still_runs(monkeypatch):
    _point_at(monkeypatch, PRODUCTION)
    monkeypatch.delenv(ALLOW_REMOTE_ENV, raising=False)
    called = []
    monkeypatch.setattr(cli, "run_website_discovery", lambda *a, **k: called.append(1) or 0)

    assert cli.main(["find-websites"]) == 0
    assert called == [1]


def test_every_commit_capable_command_is_covered(monkeypatch, capsys):
    # The guard reads the parsed `--commit` flag rather than naming commands, so a subcommand added
    # later inherits it. This asserts that holds for the ones that exist now.
    _point_at(monkeypatch, PRODUCTION)
    monkeypatch.delenv(ALLOW_REMOTE_ENV, raising=False)
    for name in ("run_website_discovery", "run_audit"):
        monkeypatch.setattr(cli, name, lambda *a, **k: 0)
    monkeypatch.setattr(cli, "run_discovery", lambda *a, **k: 0)

    for argv in (
        ["find-websites", "--commit"],
        ["audit", "--commit"],
        ["discover", "--area", "Cebu City", "--category", "restaurant", "--commit"],
    ):
        capsys.readouterr()
        assert cli.main(argv) == 2, argv
        assert "REFUSING" in capsys.readouterr().err, argv


def test_the_run_banner_names_the_target():
    # An operator should be able to tell production from local at a glance, without decoding a URL.
    assert describe_target(LOCAL).label == "localhost [local]"
    assert describe_target(PRODUCTION).label == "system.viteseo.ph [REMOTE]"
    assert CrmTarget(url="x", host="example.test", is_local=False).label.endswith("[REMOTE]")
