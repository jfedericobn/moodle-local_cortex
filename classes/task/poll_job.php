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

use local_cortex\local\config;
use local_cortex\local\course_state;
use local_cortex\local\ingest_client;

/**
 * Adhoc task: poll a Cortex ingestion job as a callback-miss safety net.
 *
 * @package    local_cortex
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class poll_job extends \core\task\adhoc_task {
    /** @var int[] Delay before each attempt: +15m, +30m, +1h, +2h. */
    const DELAYS = [900, 1800, 3600, 7200];

    /** @var int Maximum number of poll attempts. */
    const MAX_ATTEMPTS = 5;

    /**
     * Schedule the next poll attempt.
     *
     * @param int $courseid
     * @param string $jobid
     * @param int $attempt 1-based attempt number.
     */
    public static function schedule(int $courseid, string $jobid, int $attempt): void {
        if ($jobid === '' || $attempt > self::MAX_ATTEMPTS) {
            return;
        }
        $task = new self();
        $task->set_custom_data(['courseid' => $courseid, 'jobid' => $jobid, 'attempt' => $attempt]);
        $task->set_next_run_time(time() + self::delay_for_attempt($attempt));
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('task_poll_job', 'local_cortex');
    }

    /**
     * Poll Cortex and apply terminal state, or reschedule.
     */
    public function execute() {
        $data = $this->get_custom_data();
        $courseid = (int)($data->courseid ?? 0);
        $jobid = (string)($data->jobid ?? '');
        $attempt = (int)($data->attempt ?? 1);

        if ($courseid <= 0 || $jobid === '') {
            return;
        }

        $row = course_state::get($courseid);
        if (!$row || course_state::is_terminal($row->status)) {
            // Callback already completed it, or the course was disabled.
            return;
        }

        // A newer resync cleared/replaced the job id — abandon this poll chain.
        if ((string)($row->jobid ?? '') === '' || (string)$row->jobid !== $jobid) {
            return;
        }

        if (!config::is_configured()) {
            return;
        }

        $response = ingest_client::instance()->get_job($jobid, config::tenant_id());
        if ((int)$response['httpcode'] !== 200) {
            $this->maybe_reschedule($courseid, $jobid, $attempt);
            return;
        }

        // Re-check after the network call in case a resync started meanwhile.
        $row = course_state::get($courseid);
        if (!$row || (string)($row->jobid ?? '') !== $jobid || course_state::is_terminal($row->status)) {
            return;
        }

        $state = (string)($response['body']['state'] ?? '');
        if (in_array($state, ['ready', 'failed'], true)) {
            course_state::apply_terminal_state($courseid, $state, [
                'reference_code' => $response['body']['reference_code'] ?? null,
                'job_id' => $jobid,
                'stage' => $response['body']['stage'] ?? null,
                'error_code' => $response['body']['error_code'] ?? null,
                'error_message' => $response['body']['error_message'] ?? null,
            ], course_state::SOURCE_POLL);
            return;
        }

        // Still processing: refresh the Cortex-reported progress and try again later.
        course_state::record_job_progress($courseid, $jobid, $response['body']);
        $this->maybe_reschedule($courseid, $jobid, $attempt);
    }

    /**
     * Schedule the next attempt if any remain.
     *
     * @param int $courseid
     * @param string $jobid
     * @param int $attempt
     */
    private function maybe_reschedule(int $courseid, string $jobid, int $attempt): void {
        if ($attempt >= self::MAX_ATTEMPTS) {
            return;
        }
        self::schedule($courseid, $jobid, $attempt + 1);
    }

    /**
     * Delay before the given 1-based attempt.
     *
     * @param int $attempt
     * @return int
     */
    private static function delay_for_attempt(int $attempt): int {
        $idx = $attempt - 1;
        if ($idx < 0) {
            $idx = 0;
        }
        if ($idx >= count(self::DELAYS)) {
            return self::DELAYS[count(self::DELAYS) - 1];
        }
        return self::DELAYS[$idx];
    }
}
