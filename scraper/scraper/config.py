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

    # AI-ASSISTED CAPACITY MODE (the combined `openai` provider only; every other provider ignores
    # both). `ai_total_limit` is how many businesses one search may return once the AI lanes have
    # added to the OpenStreetMap sweep, which itself stays capped at the ordinary per-search limit.
    # None — the default — is the behaviour that predates the mode, exactly. `skip_ai_lanes` runs the
    # sweep alone, for a search whose area and niche the AI lanes already covered earlier in the run.
    ai_total_limit: Optional[int] = None
    skip_ai_lanes: bool = False

    # Where the scheduled command's discovery targets come from. Empty (the default) means the
    # version-controlled targets file, which is the behaviour that predates this setting. A URL points
    # at a managed registry instead — see `scraper.target_sources`. Kept as configuration rather than
    # a flag because it describes an environment, not one run.
    targets_url: str = ""

    # Whether the optional AI SEARCH PLANNER may be consulted during discovery. FALSE by default and
    # opt-in by design: Google Places is a factual provider and a Google-only run must make no model
    # call at all. The AI strategist itself stays fully built and reachable — this only decides
    # whether a run is allowed to ask for it. Set DISCOVERY_AI_STRATEGY=1 for the future AI phase.
    use_ai_strategy: bool = False

    # The ISO 3166-1 alpha-2 country discovery is restricted to. Empty means unrestricted, which is
    # the pre-existing behaviour and stays reachable for callers that predate the country control.
    #
    # It is enforced at the GEOCODER (see OpenStreetMapProvider._geocode_area), so a location outside
    # the country cannot resolve and therefore cannot become a provider request. That is deliberately
    # upstream of every provider: the fence has to stop the search, not just discard its results.
    discovery_country: str = ""

    # Where engine processes on this host share Nominatim's one-request-per-second budget and the
    # area-geocode cache (see scraper.nominatim_gate). Empty — the default — keeps the per-process
    # spacing that predates it. Must be writable and shared by every process that may run at once.
    nominatim_state_dir: str = ""

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
            # Empty by default: the version-controlled targets file stays the source until an
            # environment names a managed one.
            targets_url=os.environ.get("DISCOVERY_TARGETS_URL", ""),
            # Off unless an environment explicitly turns it on — see the field docstring.
            use_ai_strategy=os.environ.get("DISCOVERY_AI_STRATEGY", "").strip().lower()
            in ("1", "true", "yes", "on"),
            # Unset means unrestricted here; the CRM passes --country explicitly on every run it
            # starts, so the restriction travels with the request rather than depending on the
            # engine's environment being configured correctly.
            discovery_country=os.environ.get("DISCOVERY_COUNTRY", "").strip(),
            nominatim_state_dir=os.environ.get("NOMINATIM_STATE_DIR", "").strip(),
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
