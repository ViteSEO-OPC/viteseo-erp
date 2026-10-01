"""The CRM's tag-expansion lane and the engine hold selectors to the SAME business keys.

AI hardening added a PHP-side copy of the key allowlist (OpenAiTagExpander::ALLOWED_KEYS) so an
out-of-vocabulary selector never leaves the CRM. Two copies drift unless something pins them: this
reads the PHP constant from the repository and compares it with the engine's own list.
"""

import re
from pathlib import Path

from scraper.providers.openstreetmap import _CATEGORY_KEYS

PHP = (
    Path(__file__).resolve().parents[2]
    / "backend/app/Modules/Crm/Services/Discovery/OpenAiTagExpander.php"
)


def test_the_crm_and_the_engine_allow_the_same_selector_keys():
    source = PHP.read_text(encoding="utf-8")
    match = re.search(r"ALLOWED_KEYS\s*=\s*\[([^\]]*)\]", source)
    assert match, "ALLOWED_KEYS not found in OpenAiTagExpander.php"
    php_keys = re.findall(r"'([a-z_]+)'", match.group(1))
    assert sorted(php_keys) == sorted(_CATEGORY_KEYS)


def test_every_static_google_type_is_in_the_crms_place_type_allowlist():
    """The CRM now sends only Google Table A types as `includedType`. A static type missing from its
    allowlist would silently stop being sent, so the engine's table is pinned against it."""
    from scraper.providers.google_places import INCLUDED_TYPES

    source = (
        Path(__file__).resolve().parents[2]
        / "backend/app/Modules/Crm/Services/Discovery/GooglePlaceTypes.php"
    ).read_text(encoding="utf-8")
    allowed = set(re.findall(r"'([a-z0-9_]+)'", source.split("TABLE_A = [", 1)[1].split("];", 1)[0]))
    assert len(allowed) > 400
    assert set(INCLUDED_TYPES.values()) <= allowed
