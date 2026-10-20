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
 * External web service definitions for local_cortex.
 *
 * @package    local_cortex
 * @copyright  2026 onwards, Blindside Networks Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_cortex_generate_callback_url' => [
        'classname'   => 'local_cortex\external\generate_callback_url',
        'description' => 'Mint a one-time-use Cortex ingestion callback URL for a course.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/cortex:generatecallback',
    ],
    'local_cortex_get_course_status' => [
        'classname'   => 'local_cortex\external\get_course_status',
        'description' => 'Fetch Cortex ingestion status for a course (AJAX polling).',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'local/cortex:viewstatus',
    ],
];

$services = [
    'Cortex ingestion' => [
        'functions'       => [
            'local_cortex_generate_callback_url',
            'local_cortex_get_course_status',
        ],
        'restrictedusers' => 1,
        'enabled'         => 0,
        'shortname'       => 'local_cortex',
        'downloadfiles'   => 0,
        'uploadfiles'     => 0,
    ],
];
