# Architecture Decision Records

| ADR | Title | Status |
|---|---|---|
| [ADR-001](ADR-001.md) | Modular monolith in plain PHP, strangler migration | Proposed |
| [ADR-002](ADR-002.md) | Code organisation: `src/<Context>` with facades, extensions folder | Proposed |
| [ADR-003](ADR-003.md) | Customer master is `eb_persons`; CRM profiles reference `person_id` | **Accepted** |
| [ADR-004](ADR-004.md) | Tenancy: single-tenant installs + database-per-tenant for hosted SaaS | Proposed ⚑ |
| [ADR-005](ADR-005.md) | Versioned migrations with baseline adoption | Proposed |
| [ADR-006](ADR-006.md) | Permission keys + roles + scopes; pages map to keys | **Accepted** |
| [ADR-007](ADR-007.md) | Work (tasks/projects) as a new context; CRM tasks migrated into it | Proposed |
| [ADR-008](ADR-008.md) | Events via transactional outbox; generic DB job queue; cron-driven | Proposed |
| [ADR-009](ADR-009.md) | REST v2 API alongside panel RPC; YarAPI-compatible conventions | Proposed |
| [ADR-010](ADR-010.md) | Automation: Flows becomes the single rule engine; others consolidated gradually | Proposed |
| [ADR-011](ADR-011.md) | Editions via extensions; Commercial purity enforced by build | Proposed ⚑ |
| [ADR-012](ADR-012.md) | Time and locale: UTC storage, locale presentation | Proposed |
| [ADR-013](ADR-013.md) | Instagram: YarAPI as primary provider; others frozen | Proposed ⚑ |

⚑ = needs owner approval (see `../decisions-for-owner.md`).
