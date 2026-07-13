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

namespace local_cortex\task;

use local_cortex\local\backup_exporter;
use local_cortex\local\callback_token;
use local_cortex\local\config;
use local_cortex\local\concurrency;
use local_cortex\local\course_state;
use local_cortex\local\ingest_client;

/**
 * Adhoc task: build a content-only backup and submit it to Cortex asynchronously.
 *
 * @package    local_cortex
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_course extends \core\task\adhoc_task {
    /** @var int Delay (seconds) before retrying when all concurrency slots are busy. */
    const REQUEUE_DELAY = 60;

    /**
     * Queue a submission for a course (deduplicated).
     *
     * @param int $courseid
     */
    public static function queue(int $courseid): void {
        $task = new self();
        $task->set_custom_data(['courseid' => $courseid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('task_submit_course', 'local_cortex');
    }

    /**
     * Execute the submission workflow.
     */
    public function execute() {
        $data = $this->get_custom_data();
        $courseid = (int)($data->courseid ?? 0);
        if ($courseid <= 0) {
            return;
        }

        $row = course_state::get($courseid);
        if (!$row || empty($row->enabled)) {
            // Enablement was withdrawn before this task ran.
            return;
        }

        if (!config::is_configured()) {
            course_state::mark_failed($courseid, 'Cortex is not configured (ingest URL, tenant, or secret missing).');
            return;
        }

        $lock = concurrency::acquire();
        if (!$lock) {
            // No free slot: leave the row queued and try again shortly.
            $this->requeue($courseid);
            return;
        }

        try {
            course_state::mark_processing($courseid, 'backup');

            $exporter = new backup_exporter();
            try {
                $backup = $exporter->export($courseid);
            } catch (\Throwable $e) {
                // Backup failures are treated as terminal (not retried) to avoid
                // repeatedly rebuilding a backup that structurally cannot succeed
                // (e.g. user data cannot be forced off).
                course_state::mark_failed($courseid, $e->getMessage());
                return;
            }

            $max = config::max_mbz_bytes();
            if ($backup->size > $max) {
                $a = (object)['size' => $backup->size, 'limit' => $max];
                course_state::mark_failed($courseid, get_string('error_mbz_too_large', 'local_cortex', $a));
                return;
            }

            $bytes = file_get_contents($backup->path);
            if ($bytes === false) {
                course_state::mark_failed($courseid, get_string('error_backup_empty', 'local_cortex'));
                return;
            }
            course_state::set_content_hash($courseid, hash('sha256', $bytes));

            // Mint the one-time callback URL right before submit.
            $admin = get_admin();
            $token = callback_token::mint($courseid, $admin ? (int)$admin->id : 0);

            course_state::mark_processing($courseid, 'submitting');
            $response = ingest_client::instance()->submit($this->build_payload($courseid, $bytes, $token->url));

            $code = (int)$response['httpcode'];
            if ($code !== 202 && $code !== 200) {
                callback_token::expire_active_for_course($courseid);
                $detail = 'HTTP ' . $code . ' ' . \core_text::substr((string)$response['raw'], 0, 500);
                course_state::mark_failed($courseid, get_string('error_submit_failed', 'local_cortex', $detail));
                // Throw so Moodle retries the adhoc task with backoff.
                throw new \moodle_exception('error_submit_failed', 'local_cortex', '', $detail);
            }

            $body = $response['body'];
            course_state::record_submit_response($courseid, $body);

            $jobid = (string)($body['job_id'] ?? '');
            if ($jobid !== '') {
                callback_token::bind_job($courseid, $jobid);
            }

            $status = (string)($body['status'] ?? 'queued');
            if (in_array($status, ['ready', 'failed'], true)) {
                // Idempotent short-circuit: Cortex already has this content in a
                // terminal state and will not POST a callback. Apply now.
                course_state::apply_terminal_state($courseid, $status, [
                    'reference_code' => $body['reference_code'] ?? null,
                    'corpus_id' => $body['corpus_id'] ?? null,
                    'job_id' => $jobid,
                    'stage' => $status,
                ], course_state::SOURCE_IDEMPOTENT);
            } else {
                // queued/processing: wait for the callback, plus the required poll
                // safety net in case the callback is lost.
                poll_job::schedule($courseid, $jobid, 1);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Build the SubmitSourceRequest payload for a course backup.
     *
     * @param int $courseid
     * @param string $bytes Raw .mbz bytes.
     * @param string $callbackurl Minted one-time callback URL.
     * @return array
     */
    private function build_payload(int $courseid, string $bytes, string $callbackurl): array {
        $course = get_course($courseid);
        $context = \context_course::instance($courseid);
        $title = format_string($course->fullname, true, ['context' => $context]);

        return [
            'tenant_id' => config::tenant_id(),
            'external_corpus_id' => config::external_corpus_id($courseid),
            'title' => $title !== '' ? $title : ('Course ' . $courseid),
            'source_type' => 'moodle_mbz',
            'format' => 'mbz',
            'content_base64' => base64_encode($bytes),
            'minimization_asserted' => true,
            'requester' => [
                'system' => 'moodle_local_cortex',
                'actor_ref' => 'course:' . $courseid,
            ],
            'callback' => [
                'url' => $callbackurl,
            ],
        ];
    }

    /**
     * Re-queue this submission after a short delay (no free slot).
     *
     * @param int $courseid
     */
    private function requeue(int $courseid): void {
        $task = new self();
        $task->set_custom_data(['courseid' => $courseid]);
        $task->set_next_run_time(time() + self::REQUEUE_DELAY);
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
