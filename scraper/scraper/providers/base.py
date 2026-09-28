"""Discovery provider abstraction.

The scraper is provider-agnostic. Every discovery source implements ``DiscoveryProvider`` and returns
provider-neutral ``RawBusiness`` records. Swapping OpenStreetMap for Google Places (or any other
provider) later is a configuration change — no code downstream of the provider changes.
"""

from __future__ import annotations

from abc import ABC, abstractmethod
from typing import Optional

from scraper.models import RawBusiness
from scraper.partition import BBox


class DiscoveryProvider(ABC):
    """A source of business discovery. Implementations translate their native API into
    ``RawBusiness`` records and expose a stable ``name`` used as the lead ``source``."""

    #: Short, stable provider identifier stored as the lead's ``source`` (provenance only).
    name: str

    @abstractmethod
    def discover(
        self,
        *,
        area: str,
        category: str,
        limit: int,
        bbox: Optional[BBox] = None,
    ) -> list[RawBusiness]:
        """Discover up to ``limit`` businesses of ``category`` within ``area``.

        Args:
            area: A human place name. The provider resolves it unless ``bbox`` is supplied.
            category: A business category (friendly word, or a provider-native selector).
            limit: Maximum number of businesses to return.
            bbox: Optional pre-resolved bounding box (south, west, north, east). When present the
                provider MUST restrict its search to it and must not re-resolve ``area``. This is
                what lets one scope be partitioned into several smaller searches — see
                ``scraper.partition``. A provider given a box that ignored it would return the same
                scope-wide results in every cell, which is why this is a requirement and not a hint.
                ``None`` means "resolve the area yourself", i.e. the unpartitioned behaviour.

        Returns:
            Provider-neutral ``RawBusiness`` records (never provider-specific JSON).
        """
        raise NotImplementedError
