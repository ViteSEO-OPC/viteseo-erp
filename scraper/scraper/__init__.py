"""Lead Enrichment scraper — a strict data provider for CRM-Enterprise.

Discovers real businesses (OpenStreetMap in v1; provider-agnostic), normalizes them to CRM fit-data,
and submits them through the programmatic enrichment ingest. It never computes Lead Scores or KPIs,
never assigns Business Developers, never changes engagement fields or Lead Status, and holds no
frontend business logic.
"""

__version__ = "1.0.0"
