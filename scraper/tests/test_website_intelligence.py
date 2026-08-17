"""Website Intelligence detector + audit tests (pure, no network)."""

from parsel import Selector

from scraper.website_intelligence import detectors
from scraper.website_intelligence.audit import build_audit, unreachable_audit

SAMPLE_HTML = """
<html><head>
  <title>Acme Coffee Roasters</title>
  <meta name="description" content="Specialty coffee in Makati.">
</head><body>
  <nav>
    <a href="/about-us">About Us</a>
    <a href="/services">Our Services</a>
    <a href="/contact">Contact</a>
    <a href="/blog">Blog</a>
  </nav>
  <a href="https://facebook.com/acme">Facebook</a>
  <a href="https://instagram.com/acme">Instagram</a>
  <a href="mailto:hello@acme.com">Email us</a>
  <a href="tel:+63212345678">Call</a>
  <img src="/logo@2x.png">
  <form action="/subscribe"><input name="email"></form>
  <iframe src="https://www.google.com/maps/embed?pb=xyz"></iframe>
</body></html>
"""


def test_build_audit_full_page():
    payload = build_audit(SAMPLE_HTML, "https://acme.com/", 200)

    assert payload["website_reachable"] is True
    assert payload["https_enabled"] is True
    assert payload["about_page"] is True
    assert payload["contact_page"] is True
    assert payload["services_page"] is True
    assert payload["blog_page"] is True
    assert payload["careers_page"] is False
    assert payload["website_title"] == "Acme Coffee Roasters"
    assert payload["website_description"] == "Specialty coffee in Makati."
    assert "hello@acme.com" in payload["emails_found"]
    assert payload["facebook_url"] == "https://facebook.com/acme"
    assert payload["instagram_url"] == "https://instagram.com/acme"
    assert payload["phones_found"] == ["+63212345678"]
    assert payload["provider"] == "scrapy-website-intelligence"
    # Extended signals live in the audit blob.
    assert payload["audit"]["contact_form"] is True
    assert payload["audit"]["google_maps_embed"] is True


def test_emails_filter_image_false_positives():
    emails = detectors.extract_emails(SAMPLE_HTML, Selector(text=SAMPLE_HTML))
    assert all(not e.endswith((".png", ".jpg")) for e in emails)


def test_classify_pages_word_boundary_no_false_positive():
    # "aboutique" must NOT match the "about" keyword.
    links = [("https://x.com/aboutique", "Boutique")]
    assert detectors.classify_pages(links)["about"] is False


def test_build_audit_broken_homepage_is_reachable_no_pages():
    payload = build_audit("<html></html>", "https://acme.com/", 503)
    assert payload["website_reachable"] is True
    assert payload["about_page"] is False
    assert payload["audit"]["homepage_status"] == 503


def test_unreachable_audit():
    payload = unreachable_audit()
    assert payload["website_reachable"] is False
    assert payload["https_enabled"] is False
    assert payload["audit"]["unreachable"] is True
