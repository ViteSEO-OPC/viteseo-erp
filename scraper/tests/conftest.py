import json
from pathlib import Path

import pytest

FIXTURES = Path(__file__).parent / "fixtures"


def _load(name: str) -> dict:
    return json.loads((FIXTURES / name).read_text(encoding="utf-8"))


@pytest.fixture
def overpass_element() -> dict:
    """A real, recorded Overpass element (Starbucks cafe, Makati)."""
    return _load("overpass_element.json")


@pytest.fixture
def nominatim_reverse() -> dict:
    """A real, recorded Nominatim reverse-geocode response (Makati)."""
    return _load("nominatim_reverse.json")
