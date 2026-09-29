"""One Nominatim rate for every engine process on a host, and a shared area-geocode cache.

WHY THIS EXISTS. Nominatim's usage policy is an absolute maximum of one request per second. The
provider used to honour it with a ``time.sleep(1.1)`` after each of its own calls, which is correct
for exactly one process. The CRM starts one engine process per discovery task, so two tasks running
at the same time would each keep their own rhythm and could together send two requests inside the
same second. The rate has to live somewhere every process can see — a file — or it is not a rate.

HOW. An exclusive ``flock`` on ``nominatim.lock`` serialises requests across processes, and the file
holds the time the last request STARTED. A request waits until that time plus the interval, records
its own start, and runs while still holding the lock. Spacing is therefore start-to-start — what the
policy actually limits — and, because the lock is held for the whole request, never more than one
request is in flight at once.

THE CACHE. Every task geocodes its area LABEL (the nationwide anchor, e.g. "General Santos"), and a
nationwide run shares a handful of labels across dozens of cells — Run 106 geocoded ~8 distinct
places 60 times. Nominatim's answer for the same query is stable, so a success is kept for a week,
keyed by endpoint, country fence and the normalised query. A failure is never cached: an unresolved
area must be re-asked, never remembered as unresolvable.

OPT-IN, AND FAIL-SAFE. Nothing is enabled unless a state directory is configured
(``NOMINATIM_STATE_DIR``). If the directory cannot be used, ``open_gate`` says so on stderr and
returns None, and the provider takes the ungated path that predates this module — a gate that
cannot open must never cost a run its geocoding.
"""

from __future__ import annotations

import contextlib
import fcntl
import json
import os
import sys
import tempfile
import time
from pathlib import Path
from typing import Callable, Iterator, Optional

#: Nominatim usage policy: <= 1 request/second. The margin matches the provider's historical sleep.
MIN_INTERVAL_SECONDS = 1.1

#: How long a successful area geocode is reused. Place boundaries do not move week to week.
GEOCODE_CACHE_TTL_SECONDS = 7 * 24 * 3600

Bbox = tuple[float, float, float, float]


class NominatimGate:
    def __init__(
        self,
        state_dir: str,
        *,
        min_interval: float = MIN_INTERVAL_SECONDS,
        cache_ttl_seconds: float = GEOCODE_CACHE_TTL_SECONDS,
        clock: Optional[Callable[[], float]] = None,
        sleep: Optional[Callable[[float], None]] = None,
    ) -> None:
        self._dir = Path(state_dir)
        self._dir.mkdir(parents=True, exist_ok=True)
        self._lock_path = self._dir / "nominatim.lock"
        self._cache_path = self._dir / "geocode-cache.json"
        self._cache_lock_path = self._dir / "geocode-cache.lock"
        # Prove the directory is usable now, so a bad one is reported once at start-up rather than
        # failing the first real request.
        self._lock_path.touch(exist_ok=True)
        self._cache_lock_path.touch(exist_ok=True)
        self._min_interval = min_interval
        self._ttl = cache_ttl_seconds
        # Resolved at call time (not bound at import) so an injected or patched clock is honoured.
        self._clock = clock
        self._sleep = sleep

    def _now(self) -> float:
        return (self._clock or time.time)()

    @contextlib.contextmanager
    def request_slot(self) -> Iterator[None]:
        """Hold the host-wide Nominatim slot for one request."""
        with open(self._lock_path, "r+") as fh:
            fcntl.flock(fh, fcntl.LOCK_EX)
            try:
                try:
                    last = float(fh.read().strip() or 0.0)
                except ValueError:
                    last = 0.0
                wait = last + self._min_interval - self._now()
                if wait > 0:
                    (self._sleep or time.sleep)(wait)
                started = self._now()
                try:
                    yield
                finally:
                    fh.seek(0)
                    fh.truncate()
                    fh.write(repr(started))
                    fh.flush()
            finally:
                fcntl.flock(fh, fcntl.LOCK_UN)

    # ── area-geocode cache ──────────────────────────────────────────────────────────────────────

    def cached_bbox(self, key: str) -> Optional[Bbox]:
        with self._cache_locked():
            entry = self._read_cache().get(key)
        if not isinstance(entry, dict):
            return None
        try:
            if self._now() - float(entry["at"]) > self._ttl:
                return None
            south, west, north, east = (float(x) for x in entry["bbox"])
        except (KeyError, TypeError, ValueError):
            return None
        return south, west, north, east

    def store_bbox(self, key: str, bbox: Bbox) -> None:
        with self._cache_locked():
            cache = self._read_cache()
            cache[key] = {"bbox": list(bbox), "at": self._now()}
            # Atomic replace: a reader never sees a half-written file.
            fd, tmp = tempfile.mkstemp(dir=self._dir, prefix=".geocode-cache.")
            with os.fdopen(fd, "w") as out:
                json.dump(cache, out)
            os.replace(tmp, self._cache_path)

    @contextlib.contextmanager
    def _cache_locked(self) -> Iterator[None]:
        with open(self._cache_lock_path, "r+") as fh:
            fcntl.flock(fh, fcntl.LOCK_EX)
            try:
                yield
            finally:
                fcntl.flock(fh, fcntl.LOCK_UN)

    def _read_cache(self) -> dict:
        try:
            data = json.loads(self._cache_path.read_text())
        except (OSError, ValueError):
            return {}
        return data if isinstance(data, dict) else {}


def geocode_cache_key(nominatim_url: str, country_code: Optional[str], area: str) -> str:
    """The country fence is part of the key: an unrestricted answer must never serve a fenced ask."""
    return f"{nominatim_url}|{country_code or ''}|{' '.join(area.split()).casefold()}"


def open_gate(state_dir: Optional[str]) -> Optional[NominatimGate]:
    """The gate for ``state_dir``, or None when unconfigured or unusable (the ungated path)."""
    if not state_dir:
        return None
    try:
        return NominatimGate(state_dir)
    except OSError as exc:
        print(
            f"WARNING: Nominatim gate disabled — state dir {state_dir!r} unusable "
            f"({type(exc).__name__}); falling back to per-process spacing.",
            file=sys.stderr,
        )
        return None
