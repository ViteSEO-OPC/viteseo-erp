"""Scraper configuration — environment-driven, provider-agnostic.

Values come from environment variables (optionally seeded from a local ``.env`` file). Switching the
discovery provider, or pointing at a different CRM, is configuration only.
"""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


def _load_dotenv() -> None:
    """Minimal .env loader (KEY=VALUE lines) so we take no extra dependency. Existing environment
    variables always win."""
    env_path = Path(__file__).resolve().parent.parent / ".env"
    if not env_path.exists():
        return
    for line in env_path.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


@dataclass(frozen=True)
class Config:
    discovery_provider: str
    crm_base_url: str
    crm_token: str
    overpass_url: str
    nominatim_url: str
    user_agent: str
    request_timeout: float
    # OpenAI-assisted discovery. Only read when DISCOVERY_PROVIDER selects it — the default OSM path
    # never touches these, and an environment that never sets them behaves exactly as before.
    #
    # DEFAULTED, deliberately: making them required would break every existing construction site
    # (tests included) and turn an additive capability into a breaking change to the config contract.
    # An empty key is the honest default — it means "AI discovery is not configured here".
    openai_api_key: str = ""
    openai_model: str = "gpt-4o-mini"
    openai_base_url: str = "https://api.openai.com/v1"
    openai_timeout: float = 30.0

    @classmethod
    def load(cls) -> "Config":
        _load_dotenv()
        return cls(
            discovery_provider=os.environ.get("DISCOVERY_PROVIDER", "openstreetmap"),
            # Unified platform: the CRM module mounts at /api/v1/crm (shared `api` prefix +
            # the module's own `v1/crm`). The local Docker API listens on 8001. Every endpoint
            # this scraper calls is composed relative to this base, so pointing at another
            # environment is a config change only — never a code change.
            crm_base_url=os.environ.get("CRM_BASE_URL", "http://localhost:8001/api/v1/crm"),
            crm_token=os.environ.get("CRM_TOKEN", ""),
            overpass_url=os.environ.get("OVERPASS_URL", "https://overpass-api.de/api/interpreter"),
            nominatim_url=os.environ.get("NOMINATIM_URL", "https://nominatim.openstreetmap.org"),
            user_agent=os.environ.get("USER_AGENT", "CRM-Enterprise-Scraper/1.0"),
            request_timeout=float(os.environ.get("REQUEST_TIMEOUT", "60")),
            openai_api_key=os.environ.get("OPENAI_API_KEY", ""),
            openai_model=os.environ.get("OPENAI_MODEL", "gpt-4o-mini"),
            openai_base_url=os.environ.get("OPENAI_BASE_URL", "https://api.openai.com/v1"),
            # Shorter than REQUEST_TIMEOUT: a slow proposal should cost one target a few seconds and
            # then yield nothing, rather than stall a scheduled run behind it.
            openai_timeout=float(os.environ.get("OPENAI_TIMEOUT", "30")),
        )
