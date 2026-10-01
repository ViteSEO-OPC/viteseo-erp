"""Counts the outbound requests one discovery search actually made, by service.

Attached as an ``httpx`` request hook to clients a provider already owns, so it observes every real
request — retries and cache misses included, cache hits excluded — without changing how any provider
behaves. Used by the AI-assisted capacity mode only, to report what a search cost.
"""

from __future__ import annotations

from typing import Any
from urllib.parse import urlparse

import httpx


class CallCounter:
    OVERPASS = "overpass"
    NOMINATIM_SEARCH = "nominatim_search"
    NOMINATIM_REVERSE = "nominatim_reverse"
    AI = "ai"
    OTHER = "other"

    def __init__(self, *, overpass_url: str, nominatim_url: str) -> None:
        self._overpass_host = urlparse(overpass_url).netloc
        self._nominatim_host = urlparse(nominatim_url).netloc
        self.counts: dict[str, int] = {}

    def attach(self, client: httpx.Client, *, as_ai: bool = False) -> None:
        """Count every request ``client`` makes. ``as_ai``: everything it sends is a model call."""

        def hook(request: httpx.Request) -> None:
            self._add(self.AI if as_ai else self._classify(request))

        hooks = client.event_hooks
        client.event_hooks = {**hooks, "request": [*hooks.get("request", []), hook]}

    def snapshot(self) -> dict[str, Any]:
        return {key: self.counts.get(key, 0) for key in (
            self.OVERPASS, self.NOMINATIM_SEARCH, self.NOMINATIM_REVERSE, self.AI, self.OTHER,
        )}

    def _classify(self, request: httpx.Request) -> str:
        host = request.url.netloc.decode() if isinstance(request.url.netloc, bytes) else str(request.url.netloc)
        path = request.url.path
        if host == self._overpass_host:
            return self.OVERPASS
        if host == self._nominatim_host:
            return self.NOMINATIM_REVERSE if path.rstrip("/").endswith("/reverse") else self.NOMINATIM_SEARCH
        return self.OTHER

    def _add(self, key: str) -> None:
        self.counts[key] = self.counts.get(key, 0) + 1
