"""Which OpenStreetMap keys the CRM already holds — volume mode's pre-filter.

An OPTIMISATION, NEVER THE DEDUP. The CRM's ingest deduplicates on ``place_id`` whatever this says, so
a wrong answer here can only cost a wasted reverse-geocode or a wasted slot under the task cap — it can
never create a duplicate lead. That is why a failure returns ``None`` ("unknown") rather than raising:
the caller then treats every candidate as possibly new and lets the ingest decide.

Keys only travel: the CRM answers with the subset of the keys it was sent that its org already holds,
so nothing about any lead is revealed beyond what the caller submitted.
"""

from __future__ import annotations

import sys

import httpx

#: The CRM's own ceiling per request (DiscoveryKnownPlacesRequest). A cell sweep is capped below it,
#: but a caller with more keys is chunked rather than refused.
MAX_KEYS_PER_REQUEST = 500


class KnownPlacesClient:
    def __init__(self, *, base_url: str, token: str, user_agent: str, timeout: float = 30.0) -> None:
        self.base_url = base_url.rstrip("/")
        self._client = httpx.Client(
            timeout=timeout,
            headers={
                "Accept": "application/json",
                "Content-Type": "application/json",
                "User-Agent": user_agent,
                "Authorization": f"Bearer {token}",
            },
        )

    @property
    def http_client(self) -> httpx.Client:
        """For the call counter only."""
        return self._client

    def known(self, place_ids: list[str]) -> set[str] | None:
        """The subset of ``place_ids`` the CRM already holds, or None when it could not say."""
        wanted = sorted({p for p in place_ids if isinstance(p, str) and p})
        if not wanted:
            return set()
        found: set[str] = set()
        try:
            for start in range(0, len(wanted), MAX_KEYS_PER_REQUEST):
                chunk = wanted[start:start + MAX_KEYS_PER_REQUEST]
                resp = self._client.post(f"{self.base_url}/discovery/known-places", json={"place_ids": chunk})
                resp.raise_for_status()
                known = (resp.json().get("data") or {}).get("known")
                if not isinstance(known, list):
                    return None
                # Only keys that were asked about can be known; anything else is ignored.
                asked = set(chunk)
                found.update(k for k in known if isinstance(k, str) and k in asked)
        except (httpx.HTTPError, ValueError) as exc:
            print(f"  known-places lookup failed ({type(exc).__name__}) — treating every candidate as possibly new.",
                  file=sys.stderr)
            return None
        return found

    def close(self) -> None:
        self._client.close()
