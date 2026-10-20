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
 * Admin settings for local_cortex.
 *
 * @package    local_cortex
 * @copyright  2026 onwards, Blindside Networks Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/lib.php');

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_cortex', get_string('pluginname', 'local_cortex'));
    $ADMIN->add('localplugins', $settings);

    // Health status heading (reflects the last connectivity check on save).
    $settings->add(new admin_setting_heading(
        'local_cortex/health',
        get_string('settings_connectivity', 'local_cortex'),
        local_cortex_health_status_text()
    ));

    // Moodle -> Cortex: ingest base URL.
    $ingest = new admin_setting_configtext(
        'local_cortex/ingesturl',
        get_string('settings_ingesturl', 'local_cortex'),
        get_string('settings_ingesturl_desc', 'local_cortex'),
        '',
        PARAM_URL
    );
    $ingest->set_updatedcallback('local_cortex_ping_health');
    $settings->add($ingest);

    // Cortex tenant id.
    $settings->add(new admin_setting_configtext(
        'local_cortex/tenantid',
        get_string('settings_tenantid', 'local_cortex'),
        get_string('settings_tenantid_desc', 'local_cortex'),
        '',
        PARAM_RAW_TRIMMED
    ));

    // Shared HMAC signing secret (must equal Cortex CALLBACK_SIGNING_SECRET).
    $settings->add(new admin_setting_configpasswordunmask(
        'local_cortex/callbacksecret',
        get_string('settings_callbacksecret', 'local_cortex'),
        get_string('settings_callbacksecret_desc', 'local_cortex'),
        ''
    ));

    // Cortex -> Moodle: absolute base URL Cortex uses to reach this site.
    $settings->add(new admin_setting_configtext(
        'local_cortex/callbackbase',
        get_string('settings_callbackbase', 'local_cortex'),
        get_string('settings_callbackbase_desc', 'local_cortex'),
        $CFG->wwwroot,
        PARAM_URL
    ));

    // One-time callback token TTL (seconds).
    $settings->add(new admin_setting_configtext(
        'local_cortex/callbacktokenttl',
        get_string('settings_tokenttl', 'local_cortex'),
        get_string('settings_tokenttl_desc', 'local_cortex'),
        '86400',
        PARAM_INT
    ));

    // Site-wide concurrency cap for in-flight submits.
    $settings->add(new admin_setting_configtext(
        'local_cortex/maxconcurrent',
        get_string('settings_maxconcurrent', 'local_cortex'),
        get_string('settings_maxconcurrent_desc', 'local_cortex'),
        '2',
        PARAM_INT
    ));

    // Maximum raw .mbz size (bytes) before base64/POST. Default 32 MiB.
    $settings->add(new admin_setting_configtext(
        'local_cortex/maxmbzbytes',
        get_string('settings_maxmbzbytes', 'local_cortex'),
        get_string('settings_maxmbzbytes_desc', 'local_cortex'),
        (string)(32 * 1024 * 1024),
        PARAM_INT
    ));
}
