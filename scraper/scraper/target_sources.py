"""Where the discovery target list comes from.

``targets.py`` has always documented its own extension point: *"If admin-managed targets are ever
required, the seam is load_targets: point it at an API response instead of a file. Nothing else in
this module would change."* This module IS that seam, made explicit and testable.

WHY THIS MATTERS MORE THAN IT LOOKS. A version-controlled file means the set of areas a business
prospects is a deploy, not a decision — so "add Cebu tonight" is an engineering task. Making the
source pluggable is what eventually lets the CRM own that list, without the rotation, validation or
scheduling logic changing at all: those read a parsed document and neither knows nor cares whether it
arrived from disk or over HTTP.

THE FILE SOURCE REMAINS THE DEFAULT AND THE FALLBACK, exactly as OpenStreetMap does among providers.
A remote source that cannot be reached does not fail a scheduled run into silence — it is reported and
the local file answers instead, because a discovery run that skips a night is worse than one that uses
slightly stale targets.

NO SCHEMA LIVES HERE. Every source returns the same raw JSON document that the targets file has always
contained; ``targets.py`` remains the single validator. Two parsers would be two vocabularies, and the
remote one would drift.
"""

from __future__ import annotations

import json
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Optional, Protocol

import httpx


class TargetSourceError(ValueError):
    """The source could not produce a document at all (missing file, unreachable URL, bad JSON)."""


class TargetSource(Protocol):
    """Anything that can produce the raw targets document.

    Deliberately tiny: one method returning the parsed JSON object. A source decides WHERE the
    document comes from and nothing else — no validation, no defaults, no rotation.
    """

    def load(self) -> dict[str, Any]:
        """The raw targets document. Raises ``TargetSourceError`` when it cannot be produced."""
        ...

    def describe(self) -> str:
        """Short human label for logs, e.g. the path or URL. Never a credential."""
        ...


@dataclass(frozen=True)
class FileTargetSource:
    """The version-controlled JSON file — the default, and the behaviour that predates this module."""

    path: Path

    def load(self) -> dict[str, Any]:
        if not self.path.exists():
            raise TargetSourceError(f"Targets file not found: {self.path}")
        try:
            raw: Any = json.loads(self.path.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            raise TargetSourceError(f"Targets file is not valid JSON ({self.path}): {exc}") from exc
        if not isinstance(raw, dict):
            raise TargetSourceError(f"Targets file must be a JSON object, got {type(raw).__name__}.")
        return raw

    def describe(self) -> str:
        return str(self.path)


@dataclass(frozen=True)
class HttpTargetSource:
    """Targets served over HTTP — a CRM-managed registry, when one exists.

    Accepts either the bare document or one wrapped in the CRM's standard ``{"data": {...}}`` envelope,
    so the same source works against a plain static file and against a CRM endpoint without a flag
    saying which. The bearer token is the scraper's existing CRM credential; it is sent, never logged,
    and never returned by ``describe``.
    """

    url: str
    token: str = ""
    user_agent: str = "CRM-Enterprise-Scraper/1.0"
    timeout: float = 30.0

    def load(self) -> dict[str, Any]:
        headers = {"Accept": "application/json", "User-Agent": self.user_agent}
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"

        try:
            with httpx.Client(timeout=self.timeout) as client:
                response = client.get(self.url, headers=headers)
        except httpx.HTTPError as exc:
            raise TargetSourceError(
                f"Targets URL unreachable ({type(exc).__name__})."
            ) from exc

        if response.status_code >= 400:
            # The status only. A response body from an authenticated endpoint can quote the request.
            raise TargetSourceError(f"Targets URL returned HTTP {response.status_code}.")

        try:
            body: Any = response.json()
        except ValueError as exc:
            raise TargetSourceError("Targets URL did not return JSON.") from exc

        if isinstance(body, dict) and isinstance(body.get("data"), dict):
            body = body["data"]
        if not isinstance(body, dict):
            raise TargetSourceError("Targets document must be a JSON object.")
        return body

    def describe(self) -> str:
        return self.url


def resolve_source(
    *,
    path: Path | str | None = None,
    url: str = "",
    token: str = "",
    user_agent: str = "CRM-Enterprise-Scraper/1.0",
    timeout: float = 30.0,
    fallback_path: Optional[Path] = None,
) -> TargetSource:
    """Pick the source for this run: an explicit file path wins, then a configured URL, then the file.

    An explicit ``--targets-file`` beats a configured URL for the same reason ``--provider`` beats the
    CRM's stored setting: an operator naming a file for one run is an instruction, not a preference.
    """
    if path is not None:
        return FileTargetSource(Path(path))
    if url:
        return HttpTargetSource(url=url, token=token, user_agent=user_agent, timeout=timeout)
    if fallback_path is None:
        raise TargetSourceError("No targets source configured.")
    return FileTargetSource(fallback_path)


def load_document(source: TargetSource, *, fallback: Optional[TargetSource] = None) -> dict[str, Any]:
    """Read a source, falling back to another when it cannot answer.

    Announced, never silent: an operator whose CRM-managed targets stopped arriving must learn it from
    the run's own output rather than from a coverage gap discovered weeks later.
    """
    try:
        return source.load()
    except TargetSourceError as exc:
        if fallback is None:
            raise
        print(
            f"Targets source {source.describe()} unavailable ({exc}) — falling back to "
            f"{fallback.describe()}.",
            file=sys.stderr,
        )
        return fallback.load()
