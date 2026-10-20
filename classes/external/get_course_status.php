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

namespace local_cortex\external;

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_cortex\local\config;
use local_cortex\local\course_state;
use local_cortex\local\ingest_client;
use local_cortex\local\status_presenter;

defined('MOODLE_INTERNAL') || die();

/**
 * AJAX status reader for per-course Cortex ingestion progress.
 *
 * @package    local_cortex
 * @copyright  2026 onwards, Blindside Networks Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */
class get_course_status extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
        ]);
    }

    /**
     * Return the latest ingestion status for a course.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/cortex:viewstatus', $context);

        $row = course_state::get($params['courseid']);
        if ($row && in_array($row->status, [
            course_state::STATUS_QUEUED,
            course_state::STATUS_PROCESSING,
        ], true) && !empty($row->jobid) && config::is_configured()) {
            $response = ingest_client::instance()->get_job((string)$row->jobid, config::tenant_id());
            if ((int)$response['httpcode'] === 200) {
                $body = $response['body'];
                $state = (string)($body['state'] ?? '');
                if (in_array($state, ['ready', 'failed'], true)) {
                    course_state::apply_terminal_state($params['courseid'], $state, [
                        'reference_code' => $body['reference_code'] ?? null,
                        'corpus_id' => $body['corpus_id'] ?? null,
                        'job_id' => (string)$row->jobid,
                        'stage' => $body['stage'] ?? null,
                        'error_code' => $body['error_code'] ?? null,
                        'error_message' => $body['error_message'] ?? null,
                    ], course_state::SOURCE_POLL);
                } else {
                    course_state::record_job_progress($params['courseid'], (string)$row->jobid, $body);
                }
                $row = course_state::get($params['courseid']);
            }
        }
        return status_presenter::export($row);
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Raw status code'),
            'statuslabel' => new external_value(PARAM_TEXT, 'Localised status label'),
            'referencecode' => new external_value(PARAM_TEXT, 'Cortex reference code'),
            'jobid' => new external_value(PARAM_TEXT, 'Cortex job id'),
            'corpusid' => new external_value(PARAM_TEXT, 'Cortex corpus id'),
            'stage' => new external_value(PARAM_TEXT, 'Current stage'),
            'percent' => new external_value(PARAM_INT, 'Progress percent'),
            'completionsource' => new external_value(PARAM_TEXT, 'How the run completed'),
            'errormessage' => new external_value(PARAM_TEXT, 'Last error when failed'),
            'lastupdated' => new external_value(PARAM_TEXT, 'Localised last-updated time'),
            'timemodified' => new external_value(PARAM_INT, 'Unix last-modified timestamp'),
            'poll' => new external_value(PARAM_BOOL, 'True when the UI should keep polling'),
            'html' => new external_value(PARAM_RAW, 'Rendered status table HTML'),
        ]);
    }
}
