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
 * English language strings for local_cortex.
 *
 * @package    local_cortex
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Cortex knowledge';

// Capabilities.
$string['cortex:manage'] = 'Manage Cortex knowledge for a course';
$string['cortex:viewstatus'] = 'View Cortex ingestion status for a course';
$string['cortex:generatecallback'] = 'Mint one-time Cortex callback URLs';

// Navigation / pages.
$string['coursesettings'] = 'Cortex knowledge';
$string['coursestatus'] = 'Cortex knowledge status';
$string['managenav'] = 'Cortex knowledge';

// Course settings form.
$string['enable'] = 'Use Cortex knowledge for this course';
$string['enable_help'] = 'When enabled, a content-only backup of this course is sent to Cortex for knowledge ingestion. No user data is included.';
$string['resync'] = 'Resync now';
$string['resync_help'] = 'Rebuild the course backup and resubmit it to Cortex.';
$string['settingssaved'] = 'Cortex settings saved.';
$string['queued'] = 'A Cortex ingestion job has been queued for this course.';
$string['notconfigured'] = 'Cortex is not configured. Set the ingest URL, tenant, and signing secret in the site administration first.';
$string['resyncbusy'] = 'A Cortex ingestion job is already in progress for this course.';

// Status labels.
$string['status'] = 'Status';
$string['status_idle'] = 'Not submitted';
$string['status_queued'] = 'Queued';
$string['status_processing'] = 'Processing';
$string['status_ready'] = 'Ready';
$string['status_failed'] = 'Failed';
$string['status_disabled'] = 'Disabled';
$string['referencecode'] = 'Reference code';
$string['jobid'] = 'Job id';
$string['corpusid'] = 'Corpus id';
$string['stage'] = 'Stage';
$string['completionsource'] = 'Completion source';
$string['lasterror'] = 'Last error';
$string['lastupdated'] = 'Last updated';
$string['never'] = 'Never';
$string['backtocourse'] = 'Back to course';

// Errors.
$string['error_mbz_too_large'] = 'The course backup ({$a->size} bytes) exceeds the configured limit ({$a->limit} bytes) and was not submitted.';
$string['error_users_not_forced'] = 'The backup could not force user data off; refusing to submit for privacy reasons.';
$string['error_submit_failed'] = 'Submitting the backup to Cortex failed: {$a}';
$string['error_backup_empty'] = 'The course backup produced no file.';

// Settings.
$string['settings_connectivity'] = 'Connectivity';
$string['settings_ingesturl'] = 'Cortex ingest URL';
$string['settings_ingesturl_desc'] = 'Base URL of the Cortex ingest plane reachable from this Moodle server, e.g. https://cortex.example/ (no trailing /api/v1). Used for POST /api/v1/ingest/sources.';
$string['settings_tenantid'] = 'Cortex tenant id';
$string['settings_tenantid_desc'] = 'Tenant identifier used for all submissions from this site.';
$string['settings_callbacksecret'] = 'Callback signing secret';
$string['settings_callbacksecret_desc'] = 'Shared HMAC-SHA256 secret. Must exactly match the Cortex CALLBACK_SIGNING_SECRET.';
$string['settings_callbackbase'] = 'Callback base URL';
$string['settings_callbackbase_desc'] = 'Absolute base URL that the Cortex worker uses to reach this Moodle site (defaults to this site URL). One-time callbacks are minted under {base}/local/cortex/callback.php.';
$string['settings_tokenttl'] = 'Callback token TTL (seconds)';
$string['settings_tokenttl_desc'] = 'How long a minted one-time callback token remains valid.';
$string['settings_maxconcurrent'] = 'Max concurrent submits';
$string['settings_maxconcurrent_desc'] = 'Site-wide cap on Cortex submissions running at once. Extra jobs wait and retry.';
$string['settings_maxmbzbytes'] = 'Max backup size (bytes)';
$string['settings_maxmbzbytes_desc'] = 'Backups larger than this are rejected before encoding/uploading. Default 33554432 (32 MiB).';
$string['settings_health_ok'] = 'Last connectivity check to the Cortex ingest URL succeeded ({$a}).';
$string['settings_health_fail'] = 'Last connectivity check to the Cortex ingest URL failed ({$a}). Verify the URL and network reachability.';
$string['settings_health_unknown'] = 'Connectivity has not been checked yet. Save the ingest URL to test it.';

// Tasks.
$string['task_submit_course'] = 'Submit course backup to Cortex';
$string['task_poll_job'] = 'Poll Cortex ingestion job status';

// Privacy.
$string['privacy:metadata:cortex'] = 'Content-only course backups (excluding user data) are sent to the external Cortex knowledge service for ingestion.';
$string['privacy:metadata:cortex:coursecontent'] = 'Course activities, resources, and structure (no user data).';
$string['privacy:metadata:local_cortex_callback_token'] = 'One-time tokens used to authenticate ingestion completion callbacks. Contains no personal data beyond the id of the user who triggered a submission.';
$string['privacy:metadata:local_cortex_callback_token:userid'] = 'The user who triggered the ingestion submit.';
