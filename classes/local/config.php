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
 * Typed accessor for local_cortex site settings.
 *
 * @package    local_cortex
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {
    /** @var int Default maximum backup size in bytes (32 MiB). */
    const DEFAULT_MAX_MBZ_BYTES = 33554432;

    /** @var int Default one-time token TTL in seconds. */
    const DEFAULT_TOKEN_TTL = 86400;

    /** @var int Default site-wide concurrent submit cap. */
    const DEFAULT_MAX_CONCURRENT = 2;

    /**
     * @return string Ingest base URL without trailing slash.
     */
    public static function ingest_url(): string {
        return rtrim((string)get_config('local_cortex', 'ingesturl'), '/');
    }

    /**
     * @return string Cortex tenant id.
     */
    public static function tenant_id(): string {
        return trim((string)get_config('local_cortex', 'tenantid'));
    }

    /**
     * @return string Shared HMAC signing secret.
     */
    public static function callback_secret(): string {
        return (string)get_config('local_cortex', 'callbacksecret');
    }

    /**
     * @return string Absolute base URL Cortex uses to reach Moodle (no trailing slash).
     */
    public static function callback_base(): string {
        global $CFG;
        $base = trim((string)get_config('local_cortex', 'callbackbase'));
        if ($base === '') {
            $base = $CFG->wwwroot;
        }
        return rtrim($base, '/');
    }

    /**
     * @return int Token TTL in seconds.
     */
    public static function token_ttl(): int {
        $ttl = (int)get_config('local_cortex', 'callbacktokenttl');
        return $ttl > 0 ? $ttl : self::DEFAULT_TOKEN_TTL;
    }

    /**
     * @return int Site-wide concurrent submit cap.
     */
    public static function max_concurrent(): int {
        $max = (int)get_config('local_cortex', 'maxconcurrent');
        return $max > 0 ? $max : self::DEFAULT_MAX_CONCURRENT;
    }

    /**
     * @return int Max raw .mbz bytes allowed before submit.
     */
    public static function max_mbz_bytes(): int {
        $max = (int)get_config('local_cortex', 'maxmbzbytes');
        return $max > 0 ? $max : self::DEFAULT_MAX_MBZ_BYTES;
    }

    /**
     * Whether the mandatory Cortex settings are present.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return self::ingest_url() !== ''
            && self::tenant_id() !== ''
            && self::callback_secret() !== '';
    }

    /**
     * Stable external corpus id for a Moodle course.
     *
     * @param int $courseid
     * @return string
     */
    public static function external_corpus_id(int $courseid): string {
        return 'moodle-course-' . $courseid;
    }
}
