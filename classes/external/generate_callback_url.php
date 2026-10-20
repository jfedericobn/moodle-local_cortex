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
use local_cortex\local\callback_token;

defined('MOODLE_INTERNAL') || die();

/**
 * External function that mints a one-time-use Cortex callback URL for a course.
 *
 * @package    local_cortex
 * @copyright  2026 onwards, Blindside Networks Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */
class generate_callback_url extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id to mint a callback URL for'),
        ]);
    }

    /**
     * Mint a one-time callback URL.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/cortex:generatecallback', $context);

        $token = callback_token::mint($params['courseid'], (int)$USER->id);

        return [
            'callback_url' => $token->url,
            'token_id' => (int)$token->tokenid,
            'expires_at' => (int)$token->expires,
        ];
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'callback_url' => new external_value(PARAM_URL, 'One-time-use callback URL'),
            'token_id' => new external_value(PARAM_INT, 'Token row id'),
            'expires_at' => new external_value(PARAM_INT, 'Unix timestamp when the token expires'),
        ]);
    }
}
