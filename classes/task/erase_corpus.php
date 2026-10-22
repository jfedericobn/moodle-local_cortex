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
use local_cortex\local\ingest_client;

/**
 * Adhoc task: erase a deleted course's corpus in Cortex.
 *
 * Cortex hides the corpus at once and purges it asynchronously; the erasure is
 * idempotent, so a retry after a lost response is safe. Any answer other than
 * accepted (202), already erasing (200), or unknown (404) fails the task, and Moodle
 * retries it with backoff.
 *
 * @package    local_cortex
 * @copyright  2026 onwards, Blindside Networks Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */
class erase_corpus extends \core\task\adhoc_task {
    /**
     * Queue the erasure of a course's corpus.
     *
     * @param int $courseid The deleted course.
     * @param string $referencecode The course's Cortex reference code.
     */
    public static function queue(int $courseid, string $referencecode): void {
        $task = new self();
        $task->set_custom_data(['courseid' => $courseid, 'referencecode' => $referencecode]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('task_erase_corpus', 'local_cortex');
    }

    /**
     * Ask Cortex to erase the corpus.
     */
    public function execute() {
        $data = $this->get_custom_data();
        if (!config::is_configured()) {
            throw new \moodle_exception('error_erase_not_configured', 'local_cortex');
        }
        $response = ingest_client::instance()->erase((string)$data->referencecode);
        $code = $response['httpcode'];
        if (in_array($code, [200, 202, 404], true)) {
            $state = (string)($response['body']['state'] ?? ($code === 404 ? 'not found' : ''));
            mtrace("local_cortex: erasure of course {$data->courseid}'s corpus: HTTP {$code} {$state}");
            return;
        }
        throw new \moodle_exception('error_erase_failed', 'local_cortex', '', $code);
    }
}
