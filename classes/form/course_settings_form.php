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

namespace local_cortex\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Per-course Cortex enable form.
 *
 * @package    local_cortex
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_settings_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $courseid = (int)$this->_customdata['courseid'];
        $row = $this->_customdata['row'] ?? null;

        $mform->addElement('hidden', 'id', $courseid);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('advcheckbox', 'enabled', get_string('enable', 'local_cortex'));
        $mform->addHelpButton('enabled', 'enable', 'local_cortex');

        $buttons = [];
        $buttons[] = $mform->createElement('submit', 'savebutton', get_string('savechanges'));
        if ($row && !empty($row->enabled)) {
            $buttons[] = $mform->createElement('submit', 'resyncbutton', get_string('resync', 'local_cortex'));
        }
        $mform->addGroup($buttons, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');
    }
}
