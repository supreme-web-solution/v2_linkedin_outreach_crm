# ADR 001: Parallel CRM/Extension V2 with Unipile-First Integration

## Status
Accepted

## Context

The current system has high coupling between extension automation code and CRM controllers, mixed provider usage, and weak extension trust (`lk-id` header model). A clean rewrite is required without disrupting production.

## Decision

1. Build a separate Laravel `v2` CRM and separate `v2-extension` package in parallel.
2. Use Unipile as the primary provider abstraction for LinkedIn communication workflows.
3. Keep Phantom/RapidAPI only as explicit, temporary fallbacks behind feature flags.
4. Preserve active feature parity before decommissioning any v1 workflows.

## Consequences

- Faster long-term feature delivery due to cleaner boundaries.
- Temporary dual-run complexity during migration.
- Clear deprecation path for legacy provider-specific extension logic.
