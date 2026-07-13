# Moodle `local_cortex` integration

`local_cortex` connects a Moodle course to Cortex. A course manager enables
the integration for one course at a time; Moodle then builds a content-only
Moodle backup (`.mbz`) and submits it to Cortex's asynchronous Ingest Plane.
Once Cortex has processed the backup, Moodle stores the Cortex
`reference_code`, which is the stable handle needed for later RAG retrieval
and chat integration.

The plugin is implemented in the Moodle deployment repository, not this
repository:

| Location | Purpose |
| --- | --- |
| `/home/ubuntu/xdev/moodle/plugins/local_cortex/` | Durable plugin source deployed by the Moodle wrapper |
| `/home/ubuntu/xdev/moodle/data/moodle/public/local/cortex/` | Live Moodle runtime tree during development |
| `cortex/engine/content_types/moodle_mbz/` | Cortex Content Type Agent that validates and parses the submitted `.mbz` |

The durable and runtime plugin trees must remain identical. The Moodle
entrypoint copies `plugins/local_*` to `public/local/*` when the container
starts.

The deployment target is Moodle 5.3dev / PHP 8.4. The plugin's declared
minimum Moodle version is 4.5 (`2024100700`).

## Reading guide

| Document | Use it for |
| --- | --- |
| [Architecture](architecture.md) | System boundaries, data flow, lifecycle, and component responsibilities |
| [Functional specification](specification.md) | User experience, course state machine, data model, API, and security contract |
| [Configuration and deployment](configuration-and-deployment.md) | Installation, environment variables, network requirements, secrets, and first run |
| [Operations and troubleshooting](operations.md) | Monitoring, failure handling, support procedures, testing, and current limitations |

Related Cortex documentation is in the sibling Cortex repository:

- `/home/ubuntu/xdev/cortex/docs/engine/CONTENT-TYPE-AGENTS.md` — generic
  engine model and the `moodle_mbz` agent boundary.
- `/home/ubuntu/xdev/cortex/docs/development/api-notes/API-PHASE3.md` —
  asynchronous jobs and signed callbacks.
- `/home/ubuntu/xdev/cortex/docs/engine/architecture.md` — Ingest, Engine,
  and Service Plane boundaries.

## Scope

This integration implements **course ingestion**, not an in-Moodle RAG chat
experience. Its completed responsibility is:

1. create and submit a minimized course representation;
2. track ingestion to a terminal state; and
3. retain Cortex identifiers for a future Service Plane consumer.

It does not yet render Cortex answers inside Moodle, automatically reingest
when course content changes, or upload backups through an object store.
Manual **Resync now** is the current freshness mechanism.

The deployed `moodle_mbz` Cortex agent validates backup archives and extracts
Moodle `page`, `book`, and `label` modules. It currently skips other activity
types, so a successful ingestion does not guarantee every course activity is
available for RAG.
