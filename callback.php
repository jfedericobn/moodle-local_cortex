<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * One-time-use Cortex ingestion completion webhook.
 *
 * Authenticated by a single-use token in the query string plus an
 * HMAC-SHA256 signature over the raw request body. No Moodle session,
 * login, or sesskey is involved.
 *
 * @package    local_cortex
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../config.php');

use local_cortex\local\callback_token;
use local_cortex\local\config;
use local_cortex\local\course_state;
use local_cortex\local\hmac;

/**
 * Emit a JSON response and terminate.
 *
 * @param int $code HTTP status code.
 * @param array $body Response body.
 */
function local_cortex_callback_respond(int $code, array $body): void {
    header('Content-Type: application/json');
    http_response_code($code);
    echo json_encode($body);
    exit;
}

// POST only.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    local_cortex_callback_respond(405, ['status' => 'error', 'error' => 'method_not_allowed']);
}

$secret = config::callback_secret();
if ($secret === '') {
    local_cortex_callback_respond(500, ['status' => 'error', 'error' => 'not_configured']);
}

// Verify HMAC over the exact raw bytes before touching the database.
$raw = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_CORTEX_SIGNATURE'] ?? '';
if (!hmac::verify((string)$raw, (string)$signature, $secret)) {
    local_cortex_callback_respond(403, ['status' => 'error', 'error' => 'bad_signature']);
}

$token = optional_param('token', '', PARAM_ALPHANUMEXT);
if ($token === '') {
    local_cortex_callback_respond(400, ['status' => 'error', 'error' => 'missing_token']);
}

$tokenrow = callback_token::lookup($token);
if (!$tokenrow) {
    local_cortex_callback_respond(404, ['status' => 'error', 'error' => 'unknown_token']);
}

$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    local_cortex_callback_respond(400, ['status' => 'error', 'error' => 'bad_payload']);
}
$payloadjob = (string)($payload['job_id'] ?? '');

// If the token is already bound to a job, the payload job must match.
if (!empty($tokenrow->jobid) && $payloadjob !== '' && (string)$tokenrow->jobid !== $payloadjob) {
    local_cortex_callback_respond(403, ['status' => 'error', 'error' => 'job_mismatch']);
}

// Idempotent replay: a retried callback for an already-consumed token.
if ($tokenrow->status === callback_token::STATUS_USED) {
    if ($payloadjob === '' || (string)$tokenrow->jobid === $payloadjob) {
        local_cortex_callback_respond(200, ['status' => 'ok', 'idempotent' => true]);
    }
    local_cortex_callback_respond(409, ['status' => 'error', 'error' => 'token_used']);
}

// Expired token.
if ($tokenrow->status === callback_token::STATUS_EXPIRED || callback_token::is_expired($tokenrow)) {
    callback_token::mark_expired($tokenrow);
    local_cortex_callback_respond(410, ['status' => 'error', 'error' => 'token_expired']);
}

$state = (string)($payload['state'] ?? '');
if (!in_array($state, ['ready', 'failed'], true)) {
    // Cortex only calls back on terminal states; acknowledge anything else without change.
    local_cortex_callback_respond(202, ['status' => 'ignored', 'state' => $state]);
}

// Burn the token first so the terminal applicator's token sweep cannot flip it.
callback_token::mark_used($tokenrow);

course_state::apply_terminal_state((int)$tokenrow->courseid, $state, [
    'reference_code' => $payload['reference_code'] ?? null,
    'corpus_id' => $payload['corpus_id'] ?? null,
    'job_id' => $payloadjob,
    'stage' => $payload['stage'] ?? null,
    'error_code' => $payload['error_code'] ?? null,
    'error_message' => $payload['error_message'] ?? null,
], course_state::SOURCE_CALLBACK);

local_cortex_callback_respond(200, ['status' => 'ok']);
