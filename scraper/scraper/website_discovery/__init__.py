"""Candidate website discovery — finding URLs for leads that have none.

Separate from `website_intelligence`, which AUDITS a URL a lead already has. This package answers the
prior question: is there a website at all? Its output is always CANDIDATES; the CRM adjudicates them
against the unmodified identity verifier, and only a human may conclude that none exists.
"""
