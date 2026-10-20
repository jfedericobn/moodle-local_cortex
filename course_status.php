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
 * Read-only Cortex ingestion status page for a course.
 *
 * @package    local_cortex
 * @copyright  2026 onwards, Blindside Networks Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */

require(__DIR__ . '/../../config.php');

use local_cortex\local\course_state;

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);

require_login($course);
$context = context_course::instance($courseid);
require_capability('local/cortex:viewstatus', $context);

$pageurl = new moodle_url('/local/cortex/course_status.php', ['id' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('coursestatus', 'local_cortex'));
$PAGE->set_heading($course->fullname);

$row = course_state::get($courseid);

$PAGE->requires->js_call_amd('local_cortex/status_poll', 'init', [
    ['courseid' => $courseid],
]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursestatus', 'local_cortex'));
echo local_cortex_render_status($row, $courseid);
echo $OUTPUT->single_button(
    new moodle_url('/course/view.php', ['id' => $courseid]),
    get_string('backtocourse', 'local_cortex'),
    'get'
);
echo $OUTPUT->footer();
