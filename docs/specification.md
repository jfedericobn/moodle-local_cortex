# Functional specification and contracts

## Requirements implemented

| Requirement | Implementation |
| --- | --- |
| Per-course opt-in | Course-navigation page, `local/cortex:manage` capability, `enabled` column |
| No heavy browser work | Enable/resync only queues an adhoc task |
| Real Moodle export | `backup_controller` creates a standard `.mbz` backup |
| Data minimization | Fixed content-only backup setting matrix and mandatory `users=0` check |
| Asynchronous Cortex ingest | `POST /api/v1/ingest/sources` with `moodle_mbz` / `mbz` |
| Completion tracking | Signed one-time callback plus required delayed polling |
| Duplicate-safe submit | Cortex content idempotency and Moodle terminal short-circuit |
| Operations visibility | Per-course status page, state/stage/error/reference code storage |

## Roles and UI

`local_cortex` defines three course-context capabilities:

| Capability | Default archetypes | Purpose |
| --- | --- | --- |
| `local/cortex:manage` | `editingteacher`, `manager` | Enable/disable and manually resync a course |
| `local/cortex:viewstatus` | `teacher`, `editingteacher`, `manager` | View the course ingestion status |
| `local/cortex:generatecallback` | `editingteacher`, `manager` | Mint a callback URL through the external web-service contract |

The course navigation callback sends users who can manage to
`course_settings.php`; users who can only view status go to
`course_status.php`.

The form has:

- **Use Cortex knowledge for this course** — enable switch;
- **Resync now** — appears for enabled courses and queues a new ingestion only
  when no existing job is `queued` or `processing`.

Content freshness is manual in this release. Editing a course does not
automatically queue an ingestion.

## Moodle data model

### `local_cortex_course`

Exactly one row exists per course when the integration has been used.

| Field | Meaning |
| --- | --- |
| `courseid` | Unique Moodle course ID |
| `enabled` | Whether the course may be submitted |
| `externalcorpusid` | Stable Cortex identity: `moodle-course-{courseid}` |
| `referencecode` | Cortex opaque retrieval handle |
| `jobid` / `corpusid` | Latest ingest job and corpus identifiers |
| `contenthash` | SHA-256 of the last submitted raw backup |
| `status` | `idle`, `queued`, `processing`, `ready`, `failed`, or `disabled` |
| `stage` / `percent` | Latest work state from Cortex |
| `completionsource` | `callback`, `poll`, or `idempotent` |
| `errormessage` | Last non-terminal or Cortex error, truncated to 1,333 characters |
| timestamps | Creation, modification, and most recent enable time |

### `local_cortex_callback_token`

Each submit gets an unguessable, one-time callback token.

| Field | Meaning |
| --- | --- |
| `courseid` | Course receiving the terminal update |
| `tokenhash` | SHA-256 hex of the raw token; raw tokens are never stored |
| `jobid` | Bound after Cortex accepts the submission |
| `status` | `unused`, `used`, or `expired` |
| `userid` | Moodle account that triggered token minting |
| timestamps | Mint, expiry, and consume time |

Minting invalidates any earlier unused token for the same course. A terminal
course update expires any still-active token for that course.

## Content minimization specification

The plugin uses Moodle's `backup_controller` in non-interactive general mode.
It applies this root setting matrix before `execute_plan()`:

| Category | Enabled | Disabled |
| --- | --- | --- |
| Course content | `activities`, `blocks`, `files`, `filters`, `groups`, `customfield`, `contentbankcontent`, `legacyfiles` | — |
| User and user-derived data | — | `users`, `anonymize`, `role_assignments`, `comments`, `userscompletion`, `logs`, `grade_histories`, `badges`, `calendarevents`, `xapistate`, `competencies` |

`users` is the privacy-critical master switch. After applying the matrix,
Moodle verifies it is false. If it cannot be made false, backup export fails
and no content is sent to Cortex.

Moodle 5.3 has no root-level `questionbank` backup setting; question content
follows the enabled `activities` setting. Groups remain enabled in the current
implementation because they are course structure, not user enrolment data.
Review this decision for deployments where group names themselves are
sensitive.

The backup is copied into Moodle's request temporary directory, then the
filepool copy is deleted. The raw `.mbz` is size-checked before it is
base64-encoded or sent over HTTP. The default maximum is 33,554,432 bytes
(32 MiB).

## Cortex ingest API

### Submit source

`POST {CORTEX_INGEST_URL}/api/v1/ingest/sources`

Moodle sends a `SubmitSourceRequest`:

```json
{
  "tenant_id": "school-a",
  "external_corpus_id": "moodle-course-42",
  "title": "Course display name",
  "source_type": "moodle_mbz",
  "format": "mbz",
  "content_base64": "<raw-mbz-as-base64>",
  "minimization_asserted": true,
  "requester": {
    "system": "moodle_local_cortex",
    "actor_ref": "course:42"
  },
  "callback": {
    "url": "https://moodle.example/local/cortex/callback.php?token=<ott>"
  }
}
```

Exactly one of `content_base64` and `content_uri` is allowed by Cortex.
`local_cortex` currently uses inline `content_base64`; object-store
`content_uri` is a future path for larger backups.

New submissions return HTTP `202`:

```json
{
  "reference_code": "REF-...",
  "job_id": "job-...",
  "corpus_id": "corpus-...",
  "status": "queued"
}
```

For unchanged content, Cortex can return the existing job. If its returned
`status` is `ready` or `failed`, Moodle records that terminal state immediately
because no new callback will be produced.

### Job status

`GET {CORTEX_INGEST_URL}/api/v1/ingest/jobs/{job_id}?tenant_id={tenant_id}`

The response includes `state`, `stage`, `percent_complete`, `reference_code`,
`error_code`, `error_message`, retry metadata, and the Corpus state. Moodle's
poller uses `ready` and `failed` as terminal states.

## One-time callback web service

Moodle exposes `local_cortex_generate_callback_url`. It accepts `courseid` and
returns:

```json
{
  "callback_url": "https://moodle.example/local/cortex/callback.php?token=<ott>",
  "token_id": 123,
  "expires_at": 1780000000
}
```

The submit task calls the same token-minting implementation directly; it does
not make an HTTP request back to Moodle. The external function is the public
contract for service-account or integration testing use.

The declared **Cortex ingestion** Moodle external service is disabled by
default. Enable it and assign an authorized service user only when an external
caller needs this minting API. The normal in-plugin submit path does not
require the external service to be enabled.

## Callback contract and security

Cortex posts only terminal ingest outcomes to the callback URL.

### Request

```http
POST /local/cortex/callback.php?token=<one-time-token>
Content-Type: application/json
X-Cortex-Signature: <lowercase-sha256-hmac-hex>
```

The JSON payload has:

```json
{
  "tenant_id": "school-a",
  "job_id": "job-...",
  "reference_code": "REF-...",
  "corpus_id": "corpus-...",
  "state": "ready",
  "stage": "completed",
  "error_code": null,
  "error_message": null,
  "sent_at": "2026-01-01T00:00:00+00:00"
}
```

### Signature algorithm

Cortex constructs the HMAC input with:

```python
json.dumps(payload, sort_keys=True, separators=(",", ":")).encode("utf-8")
```

It calculates lowercase hexadecimal HMAC-SHA256 using
`CALLBACK_SIGNING_SECRET`, sets that digest in `X-Cortex-Signature`, and,
when HTTP dispatch is enabled, sends the exact signed bytes as the request
body. Moodle reads raw `php://input` and verifies it with `hash_hmac` and
`hash_equals`; it never re-encodes JSON to validate the signature.

### Callback endpoint behaviour

`callback.php`:

1. sets `NO_MOODLE_COOKIES` and does not use login or sesskey checks;
2. accepts POST only;
3. validates the raw-body HMAC before database work;
4. finds the SHA-256 hash of the token, rejects unknown/expired tokens;
5. requires a bound `job_id` to match when one exists;
6. burns the token before applying a terminal state; and
7. returns idempotent `200` for replay of a previously consumed token and
   matching job.

The callback URL and shared HMAC serve different purposes. The URL token
limits who can reach a one-use endpoint; the HMAC proves that the body was
created by a party holding the shared secret.
