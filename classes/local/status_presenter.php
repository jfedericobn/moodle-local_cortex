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

defined('MOODLE_INTERNAL') || die();

/**
 * Present Cortex course ingestion status for PHP pages and AJAX.
 *
 * @package    local_cortex
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class status_presenter {
    /**
     * Build a status export array for AJAX / templates.
     *
     * @param \stdClass|null $row The local_cortex_course row, or null if none yet.
     * @return array
     */
    public static function export(?\stdClass $row): array {
        $status = $row->status ?? course_state::STATUS_IDLE;
        $statuskey = 'status_' . $status;
        $statuslabel = get_string_manager()->string_exists($statuskey, 'local_cortex')
            ? get_string($statuskey, 'local_cortex')
            : $status;

        $errormessage = '';
        if ($status === course_state::STATUS_FAILED && !empty($row->errormessage)) {
            $errormessage = (string)$row->errormessage;
        }

        $timemodified = !empty($row->timemodified) ? (int)$row->timemodified : 0;
        $poll = in_array($status, [
            course_state::STATUS_QUEUED,
            course_state::STATUS_PROCESSING,
        ], true);

        $payload = [
            'status' => $status,
            'statuslabel' => $statuslabel,
            'referencecode' => (string)($row->referencecode ?? ''),
            'jobid' => (string)($row->jobid ?? ''),
            'corpusid' => (string)($row->corpusid ?? ''),
            'stage' => (string)($row->stage ?? ''),
            'percent' => (int)($row->percent ?? 0),
            'completionsource' => course_state::is_terminal($status)
                ? (string)($row->completionsource ?? '')
                : '',
            'errormessage' => $errormessage,
            'lastupdated' => $timemodified ? userdate($timemodified) : get_string('never', 'local_cortex'),
            'timemodified' => $timemodified,
            'poll' => $poll,
        ];
        $payload['html'] = self::render_table($payload);
        return $payload;
    }

    /**
     * Render the status table HTML from an export payload.
     *
     * @param array $payload From export().
     * @return string HTML table (no wrapper).
     */
    public static function render_table(array $payload): string {
        $dash = '-';
        $rows = [];
        $rows[get_string('status', 'local_cortex')] = $payload['statuslabel'] !== '' ? $payload['statuslabel'] : $dash;
        $rows[get_string('referencecode', 'local_cortex')] = $payload['referencecode'] !== ''
            ? $payload['referencecode'] : $dash;
        $rows[get_string('jobid', 'local_cortex')] = $payload['jobid'] !== '' ? $payload['jobid'] : $dash;
        $rows[get_string('corpusid', 'local_cortex')] = $payload['corpusid'] !== '' ? $payload['corpusid'] : $dash;
        $rows[get_string('stage', 'local_cortex')] = $payload['stage'] !== '' ? $payload['stage'] : $dash;
        $rows[get_string('percent', 'local_cortex')] = ((int)$payload['percent']) . '%';
        $rows[get_string('completionsource', 'local_cortex')] = $payload['completionsource'] !== ''
            ? $payload['completionsource'] : $dash;
        if (($payload['status'] ?? '') === course_state::STATUS_FAILED && $payload['errormessage'] !== '') {
            $rows[get_string('lasterror', 'local_cortex')] = s($payload['errormessage']);
        }
        $rows[get_string('lastupdated', 'local_cortex')] = $payload['lastupdated'] !== ''
            ? $payload['lastupdated'] : get_string('never', 'local_cortex');

        $table = new \html_table();
        $table->attributes['class'] = 'generaltable';
        foreach ($rows as $label => $value) {
            $table->data[] = [$label, $value];
        }
        return \html_writer::table($table);
    }

    /**
     * Render a wrapped status panel for course pages.
     *
     * @param \stdClass|null $row
     * @param int|null $courseid
     * @return string HTML
     */
    public static function render(?\stdClass $row, ?int $courseid = null): string {
        $payload = self::export($row);
        $cid = $courseid ?? (int)($row->courseid ?? 0);
        $attrs = [
            'data-region' => 'local-cortex-status',
            'data-courseid' => $cid,
            'data-status' => $payload['status'],
            'data-poll' => $payload['poll'] ? '1' : '0',
        ];
        return \html_writer::div($payload['html'], 'local-cortex-status', $attrs);
    }
}
