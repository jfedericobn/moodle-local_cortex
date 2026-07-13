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

namespace local_cortex\local;

/**
 * CRUD helpers and the shared terminal-state applicator for per-course rows.
 *
 * @package    local_cortex
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_state {
    /** @var string Table name. */
    const TABLE = 'local_cortex_course';

    const STATUS_IDLE = 'idle';
    const STATUS_QUEUED = 'queued';
    const STATUS_PROCESSING = 'processing';
    const STATUS_READY = 'ready';
    const STATUS_FAILED = 'failed';
    const STATUS_DISABLED = 'disabled';

    const SOURCE_CALLBACK = 'callback';
    const SOURCE_POLL = 'poll';
    const SOURCE_IDEMPOTENT = 'idempotent';

    /**
     * Fetch the row for a course, if any.
     *
     * @param int $courseid
     * @return \stdClass|null
     */
    public static function get(int $courseid): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['courseid' => $courseid]);
        return $record ?: null;
    }

    /**
     * Fetch or create the row for a course.
     *
     * @param int $courseid
     * @return \stdClass
     */
    public static function ensure(int $courseid): \stdClass {
        global $DB;
        $record = self::get($courseid);
        if ($record) {
            return $record;
        }
        $now = time();
        $record = (object)[
            'courseid' => $courseid,
            'enabled' => 0,
            'externalcorpusid' => config::external_corpus_id($courseid),
            'status' => self::STATUS_IDLE,
            'percent' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record(self::TABLE, $record);
        return $record;
    }

    /**
     * Persist a modified row.
     *
     * @param \stdClass $record
     */
    private static function save(\stdClass $record): void {
        global $DB;
        $record->timemodified = time();
        $DB->update_record(self::TABLE, $record);
    }

    /**
     * Turn the per-course enable flag on or off.
     *
     * @param int $courseid
     * @param bool $enabled
     * @return \stdClass The updated row.
     */
    public static function set_enabled(int $courseid, bool $enabled): \stdClass {
        $record = self::ensure($courseid);
        $wasenabled = !empty($record->enabled);
        $record->enabled = $enabled ? 1 : 0;
        if ($enabled && !$wasenabled) {
            $record->timeenabled = time();
        }
        if (!$enabled) {
            $record->status = self::STATUS_DISABLED;
        }
        self::save($record);
        return $record;
    }

    /**
     * Mark a course queued for submission.
     *
     * @param int $courseid
     */
    public static function mark_queued(int $courseid): void {
        $record = self::ensure($courseid);
        $record->status = self::STATUS_QUEUED;
        $record->stage = 'queued';
        $record->errormessage = null;
        self::save($record);
    }

    /**
     * Mark a course as actively processing at the given stage.
     *
     * @param int $courseid
     * @param string $stage
     */
    public static function mark_processing(int $courseid, string $stage): void {
        $record = self::ensure($courseid);
        $record->status = self::STATUS_PROCESSING;
        $record->stage = $stage;
        // Clear any prior failure once work is actively progressing again.
        $record->errormessage = null;
        self::save($record);
    }

    /**
     * Mark a course failed with an error message (non-Cortex, e.g. size guard).
     *
     * @param int $courseid
     * @param string $errormessage
     */
    public static function mark_failed(int $courseid, string $errormessage): void {
        $record = self::ensure($courseid);
        $record->status = self::STATUS_FAILED;
        $record->errormessage = \core_text::substr($errormessage, 0, 1333);
        self::save($record);
    }

    /**
     * Store the SHA-256 of the last submitted backup.
     *
     * @param int $courseid
     * @param string $hash
     */
    public static function set_content_hash(int $courseid, string $hash): void {
        $record = self::ensure($courseid);
        $record->contenthash = $hash;
        self::save($record);
    }

    /**
     * Persist the fields returned by a submit/reprocess 202 response.
     *
     * @param int $courseid
     * @param array $body Decoded SubmitSourceResponse.
     */
    public static function record_submit_response(int $courseid, array $body): void {
        $record = self::ensure($courseid);
        if (!empty($body['reference_code'])) {
            $record->referencecode = $body['reference_code'];
        }
        if (!empty($body['job_id'])) {
            $record->jobid = $body['job_id'];
        }
        if (!empty($body['corpus_id'])) {
            $record->corpusid = $body['corpus_id'];
        }
        $record->status = self::STATUS_PROCESSING;
        $record->stage = 'submitted';
        // Successful accept replaces any previous submit failure message.
        $record->errormessage = null;
        self::save($record);
    }

    /**
     * Whether a status string represents a terminal state.
     *
     * @param string $status
     * @return bool
     */
    public static function is_terminal(string $status): bool {
        return in_array($status, [self::STATUS_READY, self::STATUS_FAILED, self::STATUS_DISABLED], true);
    }

    /**
     * Apply a terminal Cortex state to the course row. Shared by the callback
     * endpoint, the poll task, and the idempotent submit short-circuit.
     *
     * Idempotent: if the row is already terminal for the same job, no change is
     * made and false is returned.
     *
     * @param int $courseid
     * @param string $cortexstate Cortex state ("ready" or "failed").
     * @param array $data Optional keys: reference_code, corpus_id, job_id, stage,
     *                    error_code, error_message.
     * @param string $source One of the SOURCE_* constants.
     * @return bool True if the row was updated, false if it was already applied.
     */
    public static function apply_terminal_state(
        int $courseid,
        string $cortexstate,
        array $data,
        string $source
    ): bool {
        $record = self::ensure($courseid);
        $newstatus = ($cortexstate === 'ready') ? self::STATUS_READY : self::STATUS_FAILED;

        // Already applied for the same job: no-op.
        if (self::is_terminal($record->status) && $record->status === $newstatus) {
            if (empty($data['job_id']) || (string)$record->jobid === (string)$data['job_id']) {
                return false;
            }
        }

        if (!empty($data['reference_code'])) {
            $record->referencecode = $data['reference_code'];
        }
        if (!empty($data['corpus_id'])) {
            $record->corpusid = $data['corpus_id'];
        }
        if (!empty($data['job_id'])) {
            $record->jobid = $data['job_id'];
        }
        $record->status = $newstatus;
        $record->stage = isset($data['stage']) ? (string)$data['stage'] : $record->stage;
        $record->percent = ($newstatus === self::STATUS_READY) ? 100 : $record->percent;
        $record->completionsource = $source;

        if ($newstatus === self::STATUS_FAILED) {
            $message = trim(($data['error_code'] ?? '') . ' ' . ($data['error_message'] ?? ''));
            $record->errormessage = \core_text::substr($message, 0, 1333);
        } else {
            $record->errormessage = null;
        }

        self::save($record);

        // Any outstanding one-time token is now spent.
        callback_token::expire_active_for_course($courseid);

        return true;
    }
}
