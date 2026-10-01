"""The engine's minimal .env loader.

On the production host the scraper's `.env` holds the operator's standing CRM token and is readable
by root only. The CRM's own discovery worker and enrichment tick start this engine as another user
and pass everything it needs through the environment — so an unreadable `.env` must be skipped, not
crash the run, and an existing environment variable must always win over the file.
"""

import os
from pathlib import Path

import pytest

from scraper import config as config_module


@pytest.fixture
def fake_engine_dir(tmp_path, monkeypatch):
    """Point the loader at <tmp>/.env by relocating the module's notion of its own file."""
    pkg = tmp_path / "scraper"
    pkg.mkdir()
    monkeypatch.setattr(config_module, "__file__", str(pkg / "config.py"))
    return tmp_path


def test_values_load_but_the_environment_always_wins(fake_engine_dir, monkeypatch):
    (fake_engine_dir / ".env").write_text("CRM_TOKEN=standing-operator-token\nX_DOTENV_PROBE=from-file\n")
    monkeypatch.setenv("CRM_TOKEN", "short-lived-run-token")
    # set-then-delete so monkeypatch records the variable and removes what the loader adds afterwards
    monkeypatch.setenv("X_DOTENV_PROBE", "placeholder")
    monkeypatch.delenv("X_DOTENV_PROBE")

    config_module._load_dotenv()

    assert os.environ["CRM_TOKEN"] == "short-lived-run-token"
    assert os.environ["X_DOTENV_PROBE"] == "from-file"


def test_a_missing_env_file_is_fine(fake_engine_dir):
    config_module._load_dotenv()  # no .env at all: nothing to do, no error


def test_an_unreadable_env_file_is_skipped_not_fatal(fake_engine_dir, monkeypatch):
    (fake_engine_dir / ".env").write_text("X_DOTENV_PROBE=must-not-load\n")
    monkeypatch.setenv("X_DOTENV_PROBE", "placeholder")
    monkeypatch.delenv("X_DOTENV_PROBE")

    def denied(self, *args, **kwargs):
        raise PermissionError(13, "Permission denied", str(self))

    # What the host does to the worker: stat and read of the root-only file are both refused.
    monkeypatch.setattr(Path, "is_file", denied)
    monkeypatch.setattr(Path, "read_text", denied)

    config_module._load_dotenv()

    assert "X_DOTENV_PROBE" not in os.environ
