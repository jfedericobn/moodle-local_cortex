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
 * Per-course Cortex enable/resync page.
 *
 * @package    local_cortex
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_cortex\form\course_settings_form;
use local_cortex\local\config;
use local_cortex\local\course_state;
use local_cortex\task\submit_course;

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);

require_login($course);
$context = context_course::instance($courseid);
require_capability('local/cortex:manage', $context);

$pageurl = new moodle_url('/local/cortex/course_settings.php', ['id' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('coursesettings', 'local_cortex'));
$PAGE->set_heading($course->fullname);

$row = course_state::get($courseid);
$mform = new course_settings_form($pageurl, ['courseid' => $courseid, 'row' => $row]);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
}

if ($data = $mform->get_data()) {
    $enabled = !empty($data->enabled);
    $existing = course_state::get($courseid);
    $wasenabled = $existing && !empty($existing->enabled);

    $updated = course_state::set_enabled($courseid, $enabled);
    $resync = !empty($data->resyncbutton);

    if ($enabled && !$wasenabled) {
        // Off -> on: queue the initial submission.
        local_cortex_trigger_submit($courseid);
    } else if ($enabled && $resync) {
        // Explicit resync of an already-enabled course.
        if (in_array($updated->status, [course_state::STATUS_QUEUED, course_state::STATUS_PROCESSING], true)) {
            \core\notification::info(get_string('resyncbusy', 'local_cortex'));
        } else {
            local_cortex_trigger_submit($courseid);
        }
    } else {
        \core\notification::success(get_string('settingssaved', 'local_cortex'));
    }

    redirect($pageurl);
}

$mform->set_data(['enabled' => $row ? (int)$row->enabled : 0]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursesettings', 'local_cortex'));
if (!config::is_configured()) {
    echo $OUTPUT->notification(get_string('notconfigured', 'local_cortex'), \core\output\notification::NOTIFY_WARNING);
}
echo local_cortex_render_status($row);
$mform->display();
echo $OUTPUT->footer();

/**
 * Queue a submission (guarding on configuration) and notify the user.
 *
 * @param int $courseid
 */
function local_cortex_trigger_submit(int $courseid): void {
    if (!config::is_configured()) {
        \core\notification::warning(get_string('notconfigured', 'local_cortex'));
        return;
    }
    course_state::mark_queued($courseid);
    submit_course::queue($courseid);
    \core\notification::success(get_string('queued', 'local_cortex'));
}
