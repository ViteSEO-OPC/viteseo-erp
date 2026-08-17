"""Discovery provider abstraction.

The scraper is provider-agnostic. Every discovery source implements ``DiscoveryProvider`` and returns
provider-neutral ``RawBusiness`` records. Swapping OpenStreetMap for Google Places (or any other
provider) later is a configuration change — no code downstream of the provider changes.
"""

from __future__ import annotations

from abc import ABC, abstractmethod

from scraper.models import RawBusiness


class DiscoveryProvider(ABC):
    """A source of business discovery. Implementations translate their native API into
    ``RawBusiness`` records and expose a stable ``name`` used as the lead ``source``."""

    #: Short, stable provider identifier stored as the lead's ``source`` (provenance only).
    name: str

    @abstractmethod
    def discover(self, *, area: str, category: str, limit: int) -> list[RawBusiness]:
        """Discover up to ``limit`` businesses of ``category`` within ``area``.

        Args:
            area: A human place name (e.g. "Makati City, Philippines"). The provider resolves it.
            category: A business category (friendly word like "cafe", or a provider-native selector).
            limit: Maximum number of businesses to return.

        Returns:
            Provider-neutral ``RawBusiness`` records (never provider-specific JSON).
        """
        raise NotImplementedError
