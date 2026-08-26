"""Scraper configuration — environment-driven, provider-agnostic.

Values come from environment variables (optionally seeded from a local ``.env`` file). Switching the
discovery provider, or pointing at a different CRM, is configuration only.
"""

from __future__ import annotations

import os
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Optional

import httpx


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
    # AI-assisted discovery timeout. There is deliberately NO OpenAI API key here: the CRM holds the
    # credential and makes the outbound model call, so this process never receives, stores or logs one.
    # See `fetch_crm_provider` below for how the provider itself is decided.
    #
    # DEFAULTED so existing construction sites (tests included) keep working — an additive capability
    # must not become a breaking change to the config contract.
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
            # Shorter than REQUEST_TIMEOUT: a slow proposal should cost one target a few seconds and
            # then yield nothing, rather than stall a scheduled run behind it.
            openai_timeout=float(os.environ.get("OPENAI_TIMEOUT", "30")),
        )


@dataclass(frozen=True)
class CrmProviderConfig:
    """What the CRM says the discovery provider should be, and why.

    Note ``effective_provider``, not "selected": the CRM resolves the fallback itself, so a provider
    an admin chose but has not credentialed arrives here already resolved to ``openstreetmap`` with a
    reason. That keeps the fallback rule in ONE place — a second copy in Python would be a copy that
    eventually disagrees with the CRM's own settings page.
    """

    effective_provider: str
    configured_provider: str
    reason: str
    mode: str
    is_fallback: bool


def fetch_crm_provider(config: "Config") -> Optional[CrmProviderConfig]:
    """Ask the CRM which provider to use. ``None`` when it cannot be asked.

    Returns None — never raises, and never guesses — for a missing token, an unreachable CRM, a
    non-2xx response, or a payload that is not the expected shape. The caller treats None as "fall
    back to the local configuration", which ends at OpenStreetMap: a CRM outage must degrade discovery
    to the safe baseline, not stop it and not silently keep using a provider nobody can confirm.

    No credential is involved in either direction. The response carries provider identifiers and a
    reason code; the API key stays in the CRM, which is what makes the AI path safe to operate from a
    worker at all.
    """
    if not config.crm_token:
        return None

    url = f"{config.crm_base_url.rstrip('/')}/discovery/config"
    try:
        with httpx.Client(timeout=config.request_timeout) as client:
            response = client.get(
                url,
                headers={
                    "Authorization": f"Bearer {config.crm_token}",
                    "Accept": "application/json",
                    "User-Agent": config.user_agent,
                },
            )
        if response.status_code >= 400:
            print(
                f"CRM discovery config unavailable (HTTP {response.status_code}).",
                file=sys.stderr,
            )
            return None
        payload = response.json()["data"]
        return CrmProviderConfig(
            effective_provider=str(payload["effectiveProvider"]),
            configured_provider=str(payload.get("configuredProvider", payload["effectiveProvider"])),
            reason=str(payload.get("reason", "")),
            mode=str(payload.get("mode", "live")),
            is_fallback=bool(payload.get("isFallback", False)),
        )
    except (httpx.HTTPError, ValueError, KeyError, TypeError) as exc:
        print(
            f"CRM discovery config unreachable ({type(exc).__name__}) — using local configuration.",
            file=sys.stderr,
        )
        return None
