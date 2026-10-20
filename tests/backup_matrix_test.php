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

use local_cortex\local\backup_exporter;

/**
 * Tests for the content-only backup setting matrix.
 *
 * @package    local_cortex
 * @covers     \local_cortex\local\backup_exporter
 * @copyright  2026 onwards, Blindside Networks Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */
final class backup_matrix_test extends \advanced_testcase {
    /**
     * The master user-data switch must be forced off.
     */
    public function test_users_forced_off(): void {
        $matrix = backup_exporter::SETTING_MATRIX;
        $this->assertArrayHasKey('users', $matrix);
        $this->assertFalse($matrix['users'], 'users must be forced off for content-only backups');
    }

    /**
     * Every user-data-bearing setting must be off.
     */
    public function test_user_data_settings_off(): void {
        $matrix = backup_exporter::SETTING_MATRIX;
        $off = [
            'anonymize', 'role_assignments', 'comments', 'userscompletion',
            'logs', 'grade_histories', 'badges', 'calendarevents', 'xapistate', 'competencies',
        ];
        foreach ($off as $name) {
            $this->assertArrayHasKey($name, $matrix, "$name should be present in the matrix");
            $this->assertFalse($matrix[$name], "$name must be disabled");
        }
    }

    /**
     * Content-bearing settings must be on.
     */
    public function test_content_settings_on(): void {
        $matrix = backup_exporter::SETTING_MATRIX;
        $on = ['activities', 'blocks', 'files', 'filters', 'groups', 'customfield', 'contentbankcontent', 'legacyfiles'];
        foreach ($on as $name) {
            $this->assertArrayHasKey($name, $matrix, "$name should be present in the matrix");
            $this->assertTrue($matrix[$name], "$name must be enabled");
        }
    }

    /**
     * The removed-in-5.3 questionbank root setting must not be referenced.
     */
    public function test_no_removed_questionbank_setting(): void {
        $this->assertArrayNotHasKey('questionbank', backup_exporter::SETTING_MATRIX);
    }
}
