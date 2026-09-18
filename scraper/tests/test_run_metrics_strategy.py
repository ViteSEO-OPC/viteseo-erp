"""The metrics record carries AI planning facts only when the provider reported them.

The CRM reads `strategy_applied` / `strategy_status` from this record to decide whether a discovery run
may be called AI-planned. A record from a provider that never consulted planning (OpenStreetMap, or
Google with planning off) must stay exactly as it was — no keys, not `false`.
"""

from scraper import run_metrics


def _record(stats):
    return run_metrics.build_record(
        provider="google_places",
        area="Anywhere",
        category="pharmacy",
        limit=10,
        commit=False,
        duration_ms=1,
        discovered=1,
        with_website=0,
        provider_stats=stats,
    )


def test_planning_facts_are_copied_when_reported():
    record = _record({"execution_status": "ok", "strategy_applied": True, "strategy_status": "ok"})
    assert record["strategy_applied"] is True
    assert record["strategy_status"] == "ok"


def test_a_credit_refusal_is_copied_verbatim():
    record = _record({"execution_status": "ok", "strategy_applied": False, "strategy_status": "insufficient_credit"})
    assert record["strategy_applied"] is False
    assert record["strategy_status"] == "insufficient_credit"


def test_a_provider_that_never_consulted_planning_adds_no_keys():
    record = _record({"execution_status": "ok"})
    assert "strategy_applied" not in record
    assert "strategy_status" not in record
