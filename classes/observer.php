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


namespace local_cortex;

use local_cortex\local\course_state;
use local_cortex\task\erase_corpus;

/**
 * Event observers for local_cortex.
 *
 * @package    local_cortex
 * @copyright  2026 onwards, Blindside Networks Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */
class observer {
    /**
     * A deleted course takes its Cortex corpus with it.
     *
     * Queues the erasure of the course's corpus in Cortex (retried until Cortex accepts
     * it) and removes the plugin's own rows for the course.
     *
     * @param \core\event\course_deleted $event
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;
        $courseid = (int)$event->objectid;
        $record = course_state::get($courseid);
        if ($record && !empty($record->referencecode)) {
            erase_corpus::queue($courseid, (string)$record->referencecode);
        }
        $DB->delete_records(course_state::TABLE, ['courseid' => $courseid]);
        $DB->delete_records('local_cortex_callback_token', ['courseid' => $courseid]);
    }
}
