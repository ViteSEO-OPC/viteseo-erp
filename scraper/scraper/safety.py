"""Which CRM is this process about to write to, and is it allowed to?

WHY THIS EXISTS. ``Config`` defaults ``CRM_BASE_URL`` to localhost, but a ``.env`` file overrides that
default silently, and a working ``.env`` on an operator's machine may legitimately hold a deployed
target and a real service token. Nothing in the tool printed where a run was pointed, so a local QA
command and a production write looked identical on screen and differed only by an environment
variable nobody had to think about.

The failure that motivated this is worse than writing junk rows. ``find-websites --commit`` can mark a
lead's website search EXHAUSTED, which is what tells a Business Development Manager that a business
was searched and nothing was found — the precondition for confirming it has no website at all. A
stray local run against a deployed CRM would put that claim in front of a real BD for businesses
nobody ever searched, inverting the whole point of the evidence chain.

So: every commit-capable command states its target, and a remote commit requires the operator to have
said so on purpose. Reads are not gated — pointing a read at another environment is recoverable, and
the banner makes it visible.
"""

from __future__ import annotations

import os
from dataclasses import dataclass
from typing import Mapping, Optional
from urllib.parse import urlparse

#: Opt-in for writing to a CRM that is not on this machine.
ALLOW_REMOTE_ENV = "CRM_ALLOW_REMOTE_COMMIT"

_TRUTHY = {"1", "true", "yes", "on"}

#: Hosts that are unambiguously this machine. Deliberately a SHORT allow-list of loopback names —
#: a private LAN address (10.x, 192.168.x) is somebody else's box, quite possibly staging, and
#: treating it as local is exactly the assumption this module exists to stop.
_LOCAL_HOSTS = {
    "localhost",
    "127.0.0.1",
    "::1",
    "[::1]",
    "0.0.0.0",
    "host.docker.internal",
}


@dataclass(frozen=True)
class CrmTarget:
    """The resolved CRM this run would talk to."""

    url: str
    host: str
    is_local: bool

    @property
    def label(self) -> str:
        """What the operator sees in the run banner. Shouts when the target is not this machine."""
        return f"{self.host} [local]" if self.is_local else f"{self.host} [REMOTE]"


def describe_target(base_url: str) -> CrmTarget:
    """Resolve ``CRM_BASE_URL`` to a host and decide whether it is this machine.

    An unparseable or host-less URL is treated as REMOTE. That is the safe direction: the cost of a
    false remote is one environment variable, and the cost of a false local is writing to production.
    """
    host = ""
    try:
        parsed = urlparse(base_url if "//" in base_url else f"//{base_url}")
        host = (parsed.hostname or "").strip().lower()
    except ValueError:
        host = ""

    return CrmTarget(url=base_url, host=host or "unknown", is_local=host in _LOCAL_HOSTS)


def remote_commit_allowed(env: Optional[Mapping[str, str]] = None) -> bool:
    """Whether the operator has explicitly opted in to writing to a remote CRM."""
    source = os.environ if env is None else env

    return str(source.get(ALLOW_REMOTE_ENV, "")).strip().lower() in _TRUTHY


def refuse_remote_commit(
    target: CrmTarget, commit: bool, env: Optional[Mapping[str, str]] = None
) -> Optional[str]:
    """The reason this commit must not proceed, or None when it may.

    Returns a message rather than raising or exiting, so the caller keeps ownership of its exit code
    and output stream — and so this is trivial to assert on in tests.
    """
    if not commit or target.is_local or remote_commit_allowed(env):
        return None

    return (
        f"REFUSING to --commit to a remote CRM: {target.host}\n"
        f"  target: {target.url}\n"
        f"  This is not localhost, so it may be staging or production. Writes from a local QA run "
        f"can mark real leads as searched-and-exhausted, which asks real BDs to act on evidence "
        f"that does not exist.\n"
        f"  If you are sure, re-run with {ALLOW_REMOTE_ENV}=1 set. To point at your local CRM "
        f"instead, set CRM_BASE_URL=http://localhost:8001/api/v1/crm"
    )
