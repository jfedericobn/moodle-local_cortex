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
 * Produce a silent, content-only .mbz backup of a course.
 *
 * User data is forced off (the "minimization" guarantee). If user data cannot
 * be disabled, the export aborts rather than leaking personal data to Cortex.
 *
 * @package    local_cortex
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_exporter {
    /**
     * Root settings forced to content-only values. Keys are backup root task
     * setting names for Moodle 5.3 (the "questionbank" root setting was removed
     * in 5.3; question bank content follows "activities").
     *
     * @var array<string,bool>
     */
    const SETTING_MATRIX = [
        // User data: hard off.
        'users' => false,
        'anonymize' => false,
        'role_assignments' => false,
        'comments' => false,
        'userscompletion' => false,
        'logs' => false,
        'grade_histories' => false,
        'badges' => false,
        'calendarevents' => false,
        'xapistate' => false,
        'competencies' => false,
        // Content: on.
        'activities' => true,
        'blocks' => true,
        'files' => true,
        'filters' => true,
        'groups' => true,
        'customfield' => true,
        'contentbankcontent' => true,
        'legacyfiles' => true,
    ];

    /**
     * Build a content-only backup and copy it to a temp .mbz path.
     *
     * @param int $courseid
     * @return \stdClass Object with ->path (string) and ->size (int bytes).
     * @throws \moodle_exception When user data cannot be forced off or no file is produced.
     */
    public function export(int $courseid): \stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        $admin = get_admin();
        if (!$admin) {
            throw new \moodle_exception('error_backup_empty', 'local_cortex');
        }
        $userid = $admin->id;

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $courseid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $userid
        );

        try {
            $plan = $bc->get_plan();

            foreach (self::SETTING_MATRIX as $name => $value) {
                self::apply_setting($plan, $name, $value);
            }

            // Enforce the minimization guarantee: user data MUST be off.
            if ($plan->setting_exists('users')) {
                $usersvalue = $plan->get_setting('users')->get_value();
                if (!empty($usersvalue)) {
                    throw new \moodle_exception('error_users_not_forced', 'local_cortex');
                }
            }

            self::apply_setting($plan, 'filename', 'cortex-content-' . $courseid . '.mbz');

            $bc->set_status(\backup::STATUS_AWAITING);
            $bc->execute_plan();

            $results = $bc->get_results();
            if (empty($results['backup_destination'])) {
                throw new \moodle_exception('error_backup_empty', 'local_cortex');
            }

            /** @var \stored_file $file */
            $file = $results['backup_destination'];
            $size = (int)$file->get_filesize();

            $dir = make_request_directory();
            $path = $dir . '/cortex-content-' . $courseid . '.mbz';

            if (!$file->copy_content_to($path)) {
                $file->delete();
                throw new \moodle_exception('error_backup_empty', 'local_cortex');
            }

            // Remove the copy left in the filepool (user private backup area for
            // content-only backups) so nothing lingers server-side.
            $file->delete();

            return (object)['path' => $path, 'size' => $size];
        } finally {
            $bc->destroy();
        }
    }

    /**
     * Safely set a root backup plan setting, unlocking hierarchy locks first.
     *
     * @param \base_plan $plan
     * @param string $name
     * @param mixed $value
     */
    private static function apply_setting(\base_plan $plan, string $name, $value): void {
        if (!$plan->setting_exists($name)) {
            return;
        }
        $setting = $plan->get_setting($name);
        if ($setting->get_status() !== \base_setting::NOT_LOCKED) {
            $setting->set_status(\base_setting::NOT_LOCKED);
        }
        $setting->set_value($value);
    }
}
