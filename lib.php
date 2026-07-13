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
 * Callbacks and helper functions for local_cortex.
 *
 * @package    local_cortex
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add a Cortex knowledge entry to the course administration navigation.
 *
 * @param navigation_node $navigation The course settings navigation node.
 * @param stdClass $course The course.
 * @param context_course $context The course context.
 */
function local_cortex_extend_navigation_course($navigation, $course, $context) {
    $canmanage = has_capability('local/cortex:manage', $context);
    $canview = has_capability('local/cortex:viewstatus', $context);
    if (!$canmanage && !$canview) {
        return;
    }

    if ($canmanage) {
        $url = new moodle_url('/local/cortex/course_settings.php', ['id' => $course->id]);
    } else {
        $url = new moodle_url('/local/cortex/course_status.php', ['id' => $course->id]);
    }

    $navigation->add(
        get_string('managenav', 'local_cortex'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_cortex',
        new pix_icon('i/settings', '')
    );
}

/**
 * Settings updated callback: ping the Cortex ingest URL and remember the result.
 */
function local_cortex_ping_health() {
    $ok = \local_cortex\local\ingest_client::instance()->health();
    set_config('lasthealthok', $ok ? 1 : 0, 'local_cortex');
    set_config('lasthealthtime', time(), 'local_cortex');
}

/**
 * Render a read-only status summary for a course's Cortex ingestion row.
 *
 * @param stdClass|null $row The local_cortex_course row, or null if none yet.
 * @return string HTML.
 */
function local_cortex_render_status(?stdClass $row): string {
    $status = $row->status ?? \local_cortex\local\course_state::STATUS_IDLE;
    $statuskey = 'status_' . $status;
    $statuslabel = get_string_manager()->string_exists($statuskey, 'local_cortex')
        ? get_string($statuskey, 'local_cortex')
        : $status;

    $rows = [];
    $rows[get_string('status', 'local_cortex')] = $statuslabel;
    $rows[get_string('referencecode', 'local_cortex')] = $row->referencecode ?? '-';
    $rows[get_string('jobid', 'local_cortex')] = $row->jobid ?? '-';
    $rows[get_string('corpusid', 'local_cortex')] = $row->corpusid ?? '-';
    $rows[get_string('stage', 'local_cortex')] = $row->stage ?? '-';
    $rows[get_string('completionsource', 'local_cortex')] = $row->completionsource ?? '-';
    // Only surface Last error for a failed run; stale messages from earlier
    // attempts must not appear once status has moved on.
    if (($row->status ?? '') === \local_cortex\local\course_state::STATUS_FAILED
            && !empty($row->errormessage)) {
        $rows[get_string('lasterror', 'local_cortex')] = s($row->errormessage);
    }
    $rows[get_string('lastupdated', 'local_cortex')] = !empty($row->timemodified)
        ? userdate((int)$row->timemodified)
        : get_string('never', 'local_cortex');

    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    foreach ($rows as $label => $value) {
        $table->data[] = [$label, $value];
    }
    return html_writer::table($table);
}

/**
 * Human-readable connectivity status for the settings page heading.
 *
 * @return string
 */
function local_cortex_health_status_text() {
    $checked = get_config('local_cortex', 'lasthealthtime');
    if (empty($checked)) {
        return get_string('settings_health_unknown', 'local_cortex');
    }
    $when = userdate((int)$checked);
    if (get_config('local_cortex', 'lasthealthok')) {
        return get_string('settings_health_ok', 'local_cortex', $when);
    }
    return get_string('settings_health_fail', 'local_cortex', $when);
}
