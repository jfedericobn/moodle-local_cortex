# Operations, testing, and troubleshooting

## Operational model

The integration has two asynchronous systems:

1. Moodle adhoc tasks create and submit course backups.
2. Cortex worker tasks parse and ingest the submitted source.

Moodle cron must run for the first system. Cortex's queue worker must run for
the second. A healthy HTTP endpoint alone is insufficient to prove either
queue is progressing.

## Status interpretation

The course status page displays fields from `local_cortex_course`.

| Moodle status | Meaning | Typical next action |
| --- | --- | --- |
| `idle` | No course submission has occurred | Enable the course |
| `queued` | Moodle has queued work or is waiting for a concurrency slot | Verify Moodle cron and task queue |
| `processing` | Backup/submit is in progress or Cortex has accepted the job | Check stage, Cortex job API, and callbacks |
| `ready` | Cortex completed ingestion | `reference_code` is ready for future Service Plane use |
| `failed` | Backup, submit, or Cortex processing failed | Read `errormessage`; see troubleshooting |
| `disabled` | Course was switched off | Re-enable and use Resync to ingest again |

`completionsource` describes how the final status was observed:

| Value | Meaning |
| --- | --- |
| `callback` | Normal signed Cortex callback completed the course |
| `poll` | Moodle's delayed job-status poll detected terminal Cortex state |
| `idempotent` | Cortex returned an existing terminal job for unchanged content |

## Expected task behaviour

### `submit_course`

The task:

1. verifies the course is still enabled and configuration exists;
2. obtains one of `maxconcurrent` lock slots, otherwise queues itself after 60
   seconds;
3. creates the minimized `.mbz`;
4. rejects raw backups above `maxmbzbytes`;
5. computes and stores `contenthash`;
6. mints a one-time callback token;
7. submits to Cortex; and
8. releases the concurrency lock after Cortex responds.

Backup failures are treated as terminal Moodle failures. HTTP submit failures
raise the adhoc task so Moodle can retry it with normal task retry/backoff
behaviour.

### `poll_job`

For a non-terminal submit response, Moodle schedules polls after:

```text
15 minutes, 30 minutes, 1 hour, 2 hours, 2 hours
```

The final repeated two-hour delay results from the five-attempt cap and the
configured four-delay array. The implementation stops immediately if a
callback already made the row terminal. If all attempts finish while the job
is still non-terminal, the course remains `processing`; investigate Cortex
and use the job ID rather than assuming the ingestion failed.

## Common failures

### Course stays `queued`

Likely causes:

- Moodle cron is not running;
- no free submit slot is available;
- the adhoc task failed repeatedly before it reached Cortex.

Actions:

1. Inspect Moodle adhoc task administration/logs for
   `\local_cortex\task\submit_course`.
2. Confirm the `cron` container/service runs against the same Moodle database.
3. Check for long-running backups consuming lock slots.
4. Check the course status `errormessage`.

### Course fails before a job ID appears

Likely causes:

- plugin configuration is incomplete;
- the backup cannot force `users=0`;
- the backup produces no destination file;
- raw `.mbz` size exceeds `maxmbzbytes`;
- Moodle cannot reach Cortex; or
- Cortex rejects the request as invalid.

Actions:

1. Verify site configuration and the saved ingest health result.
2. Inspect the Moodle web/cron logs and `errormessage`.
3. For `mbz_too_large`, reduce course backup size, increase the limit after
   assessing infrastructure limits, or implement Cortex `content_uri` object
   storage submission instead of inline base64.
4. Verify `moodle_mbz` is listed by the Cortex content-type endpoint.

### Job ID exists but course never becomes `ready` or `failed`

Likely causes:

- Cortex worker is not consuming its queue;
- Cortex ingest is retrying or stuck;
- the callback base URL is unreachable;
- callback HMAC secrets differ; or
- both callbacks and the bounded Moodle poll sequence have failed/exhausted.

Actions:

1. Query Cortex directly:

   ```bash
   curl -fsS -H "Authorization: Bearer $CORTEX_API_KEY" \
     "$CORTEX_INGEST_URL/api/v1/ingest/jobs/$JOB_ID"
   ```

2. Inspect Cortex worker logs and queue health (a Cortex operator token, on the
   Management plane):

   ```bash
   curl -fsS -H "Authorization: Bearer $CORTEX_OPERATOR_TOKEN" \
     "$CORTEX_MANAGEMENT_URL/api/v1/management/health/queue"
   ```

3. Confirm `CALLBACK_DISPATCH_MODE=http`.
4. Confirm both sides use the exact same signing-secret bytes.
5. Test the callback route from the Cortex worker network, not only from a
   developer workstation.
6. Compare the `job_id`, `state`, `stage`, and error fields from Cortex with
   Moodle's course row.

### Callback receives 403

`403 bad_signature` indicates the raw request body did not verify under
Moodle's configured shared secret. Check:

- `CORTEX_CALLBACK_SECRET` equals `CALLBACK_SIGNING_SECRET`;
- no proxy modifies the request body;
- deployed Cortex includes the exact-signed-body dispatch behaviour in
  `cortex/jobs/callbacks.py`; and
- the callback header is not removed by a proxy.

`403 job_mismatch` indicates a valid token was bound to another Cortex job.
Treat it as a correlation/configuration issue; do not manually replay an
arbitrary callback.

### Callback receives 404 or 410

`404 unknown_token` means the one-time token is unrecognised. `410` means the
token was expired or invalidated. Common explanations are:

- a later Resync minted a replacement token;
- the callback arrived after the default 24-hour TTL;
- a terminal state was already applied; or
- the deployment has different Moodle databases/runtime instances.

The polling path should still update the course for a reachable Cortex job.

## Manual diagnostics

Cortex 0.2 authenticates every Ingest request: `$CORTEX_API_KEY` is the key
saved in the plugin's **Cortex API key** setting (scopes `ingest:write` and
`ingest:read`). The tenant comes from the key.

### Discover supported Cortex types

```bash
curl -fsS -H "Authorization: Bearer $CORTEX_API_KEY" \
  "$CORTEX_INGEST_URL/api/v1/ingest/content-types"
```

Confirm an entry for:

```json
{
  "source_type": "moodle_mbz",
  "supported_formats": ["mbz"]
}
```

### Inspect a job

```bash
curl -fsS -H "Authorization: Bearer $CORTEX_API_KEY" \
  "$CORTEX_INGEST_URL/api/v1/ingest/jobs/$JOB_ID"
```

Keep the result correlated with the Moodle course's `referencecode`, `jobid`,
and `corpusid`. Do not use `corpusid` as the consumer-facing retrieval handle;
future Service Plane integration should use `referencecode` plus `tenant_id`.

### Inspect Moodle persistence

Use Moodle administration/database tooling appropriate to the deployment to
inspect:

```text
mdl_local_cortex_course
mdl_local_cortex_callback_token
```

The real table prefix can differ from `mdl_`. Never expose `tokenhash` or raw
callback URLs in user-facing diagnostic output.

## Test coverage and commands

The integration includes:

| Test | Coverage |
| --- | --- |
| `local_cortex/tests/hmac_test.php` | Cortex-compatible HMAC golden vector, tamper rejection, empty-secret failure |
| `local_cortex/tests/backup_matrix_test.php` | Required content-only backup settings; confirms the removed Moodle 5.3 `questionbank` setting is not referenced |
| `cortex/tests/test_phase3_async.py::test_http_callback_transmits_exact_signed_bytes` | Cortex sends the same canonical bytes it signs |

After a Moodle test environment is provisioned:

```bash
cd /var/www/html/moodle
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit local/cortex
```

For Cortex:

```bash
cd /home/ubuntu/xdev/cortex
python -m pytest \
  tests/test_phase3_async.py::test_http_callback_transmits_exact_signed_bytes \
  tests/test_phase3_async.py::test_callback_is_signed_and_recorded -q
```

## Current limitations and planned follow-ups

These are intentional scope limits, not hidden behaviour:

- **No Moodle RAG UI yet:** ingestion makes a `reference_code` available, but
  the plugin does not call Cortex Service Plane retrieval/chat endpoints.
- **Manual freshness only:** no event-driven or scheduled course reingestion.
- **Inline upload only:** base64 adds transport/memory overhead. For large
  courses, add a pre-upload flow and use Cortex `content_uri`.
- **Bounded polling:** if the job remains active after the scheduled poll
  window, status remains `processing` until another signal or operator action.
- **Cortex callback delivery has no retry loop:** Moodle polling is mandatory
  precisely because callbacks may be missed.
- **Course disable does not delete Cortex data:** retention/deletion workflow
  must be designed separately before adding a destructive user control.
