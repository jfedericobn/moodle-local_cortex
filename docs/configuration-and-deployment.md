# Configuration and deployment

## Prerequisites

Before enabling a course, ensure:

- Moodle 5.3dev / PHP 8.4 is deployed with `local_cortex`;
- Moodle's adhoc task cron is running;
- Cortex is running with the `moodle_mbz` Content Type Agent available;
- both network paths described below are reachable;
- a strong shared callback secret is available; and
- the Moodle deployment repository contains the durable plugin tree at
  `plugins/local_cortex/`.

The implementation was developed against:

```text
/home/ubuntu/xdev/moodle/plugins/local_cortex/
/home/ubuntu/xdev/moodle/data/moodle/public/local/cortex/
/home/ubuntu/xdev/cortex/
```

## Install the plugin

The Moodle wrapper's `entrypoint.sh` recognises `local_*` plugin directories
and synchronizes:

```text
plugins/local_cortex/
  -> public/local/cortex/
```

For a new or changed plugin release:

1. Deploy the Moodle wrapper containing `plugins/local_cortex`.
2. Restart the Moodle application container so the entrypoint copies the
   plugin to its runtime source tree.
3. Open **Site administration → Notifications**, or run Moodle's normal
   upgrade command, to install/upgrade the plugin tables and web-service
   definitions.
4. Confirm that `local_cortex_course` and `local_cortex_callback_token` were
   created and that the **Cortex knowledge** local-plugin settings page appears.

Do not treat only `data/moodle/public/local/cortex` as the source of record:
that runtime tree may be recreated when Moodle core is cloned or reset.

## Required configuration

The plugin may be configured through Moodle's local-plugin admin page or
seeded from deployment environment variables. The entrypoint applies supplied
environment values to Moodle configuration on every startup.

### Moodle settings

| Moodle config key | Meaning | Default |
| --- | --- | --- |
| `local_cortex/ingesturl` | Cortex base URL reachable by Moodle; omit `/api/v1` | none |
| `local_cortex/tenantid` | Cortex tenant ID for all Moodle submissions | none |
| `local_cortex/callbacksecret` | HMAC secret, equal to Cortex callback signing secret | none |
| `local_cortex/callbackbase` | Absolute Moodle base URL reachable by Cortex workers | Moodle `$CFG->wwwroot` |
| `local_cortex/callbacktokenttl` | One-time callback lifetime in seconds | `86400` |
| `local_cortex/maxconcurrent` | Site-wide parallel backup/submit cap | `2` |
| `local_cortex/maxmbzbytes` | Maximum raw backup bytes before base64/submit | `33554432` (32 MiB) |

The `ingesturl` field runs a health probe when saved. It verifies the
Moodle-to-Cortex direction only; it does not verify that Cortex can call
Moodle.

### Deployment environment

Set these values in the Moodle deployment `.env` file:

```dotenv
# Moodle -> Cortex. No trailing /api/v1.
CORTEX_INGEST_URL=https://cortex.internal.example
CORTEX_TENANT_ID=school-a

# Shared with Cortex CALLBACK_SIGNING_SECRET.
CORTEX_CALLBACK_SECRET=<generate-and-store-a-strong-secret>

# Cortex -> Moodle. Must be externally routable from Cortex workers.
CORTEX_CALLBACK_BASE=https://moodle.example

# Optional overrides.
CORTEX_TOKEN_TTL=86400
CORTEX_MAX_CONCURRENT=2
CORTEX_MAX_MBZ_BYTES=33554432
```

The Moodle `compose.yml` passes these variables to the `moodle` service.
`entrypoint.sh` seeds the corresponding `local_cortex` config values when
`CORTEX_INGEST_URL`, `CORTEX_TENANT_ID`, and `CORTEX_CALLBACK_SECRET` are all
nonempty. `CORTEX_CALLBACK_BASE` falls back to `MOODLE_URL` if unset.

### Cortex configuration

Set these values in Cortex's own environment:

```dotenv
CALLBACK_DISPATCH_MODE=http
CALLBACK_SIGNING_SECRET=<exactly-the-value-of-CORTEX_CALLBACK_SECRET>
```

`CALLBACK_DISPATCH_MODE=placeholder` records callbacks internally for
development/testing and does **not** deliver them to Moodle. `http` is required
for normal integration operation.

The Cortex ingest API must be exposed to Moodle and the deployed processor
registry must list `moodle_mbz` with `mbz` support:

```bash
curl -fsS "$CORTEX_INGEST_URL/api/v1/ingest/content-types"
```

## Network and reverse-proxy requirements

### Direction 1: Moodle to Cortex

Moodle's application and cron containers need TCP/TLS access to:

```text
{CORTEX_INGEST_URL}/api/v1/ingest
{CORTEX_INGEST_URL}/api/v1/ingest/sources
{CORTEX_INGEST_URL}/api/v1/ingest/jobs/{job_id}
```

For private network targets, Moodle may reject the request through its HTTP
security settings. Development mode clears `curlsecurityblockedhosts`. In
production, explicitly allow the Cortex hostname/IP and port in **Site
administration → Security → HTTP security**.

### Direction 2: Cortex to Moodle

Cortex worker processes need access to:

```text
{CORTEX_CALLBACK_BASE}/local/cortex/callback.php?token=<one-time-token>
```

Requirements:

- use an absolute URL, including scheme and any nonstandard port;
- configure DNS, proxy, TLS, firewall, and container networks so workers can
  reach the callback host;
- preserve the query string through the reverse proxy;
- permit unauthenticated POST at this route. Moodle authenticates it with the
  token and HMAC, not a Moodle session.

The Moodle wrapper's Compose configuration connects the `moodle` service to
an external `cortex-internal` network. That only helps if the Cortex stack
also joins the same named external network and the configured host resolves
inside it.

### Required cron connectivity check

`submit_course` and `poll_job` are Moodle **adhoc tasks**, so they run in the
`cron` container rather than in the `moodle` Apache container. The current
Moodle `compose.yml` attaches only the `moodle` service to
`cortex-internal`; the `cron` service is not attached to that network.

If `CORTEX_INGEST_URL` is private to `cortex-internal`, update the deployment
before enabling courses so `cron` can reach it too:

```yaml
services:
  cron:
    networks:
      - default
      - cortex-internal
```

Alternatively, configure an ingest URL routable from the cron container's
existing network. This is not optional for a private Cortex endpoint: Moodle's
health probe runs in the application container, but actual submit and poll
traffic originates from cron.

## Secrets

`CORTEX_CALLBACK_SECRET` / `CALLBACK_SIGNING_SECRET` is an authentication
secret, not a label. Manage it as a production secret:

- generate a high-entropy random value;
- never commit it to `.env.template`, README examples, or logs;
- use the same current value in both applications;
- rotate it in a coordinated maintenance window; and
- avoid exposing callback URLs in application logs, because they include
  one-time tokens.

Token URLs are intentionally short-lived and single-use, but HTTPS remains
required outside isolated development environments.

## Initial verification procedure

After plugin upgrade and configuration:

1. Confirm the ingest health indication becomes successful after saving the
   Moodle `ingesturl`.
2. Confirm `GET /api/v1/ingest/content-types` contains the `moodle_mbz`
   content type.
3. In a non-sensitive test course, use the **Cortex knowledge** course page to
   enable the integration.
4. Ensure Moodle cron executes the queued `submit_course` adhoc task.
5. Open the course status page and confirm `job_id` and `reference_code` are
   populated.
6. Confirm Cortex is configured with HTTP callback dispatch and inspect worker
   logs for a successful callback delivery.
7. Confirm the Moodle row becomes `ready` with completion source `callback`.
8. To test resilience, temporarily prevent callback delivery and verify the
   course becomes terminal through `poll` after the scheduled job-status
   checks.

## Upgrade and rollback notes

- Run Moodle's upgrade flow whenever `version.php` or `db/install.xml` changes.
- Preserve the `plugins/local_cortex` durable tree before replacing Moodle
  core/runtime volumes.
- Disabling a course is not a Cortex deletion request. A rollback of Moodle
  code does not remove previously ingested Cortex corpora.
- To stop future submissions immediately, disable the course integration or
  remove its `local/cortex:manage` capability. Existing Cortex jobs may still
  complete; their callback is safely token-validated.
