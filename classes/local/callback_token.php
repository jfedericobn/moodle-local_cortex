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
 * Mint and consume one-time-use Cortex callback tokens.
 *
 * Only the SHA-256 hash of the raw token is ever stored. The raw token is
 * embedded in the callback URL handed to Cortex.
 *
 * @package    local_cortex
 * @copyright  2026 onwards, Blindside Networks Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */
class callback_token {
    /** @var string Table name. */
    const TABLE = 'local_cortex_callback_token';

    const STATUS_UNUSED = 'unused';
    const STATUS_USED = 'used';
    const STATUS_EXPIRED = 'expired';

    /**
     * Hash a raw token for storage/lookup.
     *
     * @param string $raw
     * @return string
     */
    public static function hash(string $raw): string {
        return hash('sha256', $raw);
    }

    /**
     * Mint a fresh one-time token + absolute callback URL for a course.
     *
     * Any previously unused token for the course is expired first so only one
     * token is active at a time.
     *
     * @param int $courseid
     * @param int $userid
     * @return \stdClass Object with ->raw, ->url and ->tokenid.
     */
    public static function mint(int $courseid, int $userid): \stdClass {
        global $DB;

        self::expire_active_for_course($courseid);

        $raw = bin2hex(random_bytes(32));
        $now = time();
        $expires = $now + config::token_ttl();
        $record = (object)[
            'courseid' => $courseid,
            'tokenhash' => self::hash($raw),
            'status' => self::STATUS_UNUSED,
            'userid' => $userid,
            'timecreated' => $now,
            'timeexpires' => $expires,
        ];
        $tokenid = $DB->insert_record(self::TABLE, $record);

        $url = config::callback_base() . '/local/cortex/callback.php?token=' . $raw;

        return (object)['raw' => $raw, 'url' => $url, 'tokenid' => $tokenid, 'expires' => $expires];
    }

    /**
     * Expire any unused tokens for a course.
     *
     * @param int $courseid
     */
    public static function expire_active_for_course(int $courseid): void {
        global $DB;
        $DB->set_field_select(
            self::TABLE,
            'status',
            self::STATUS_EXPIRED,
            'courseid = :courseid AND status = :status',
            ['courseid' => $courseid, 'status' => self::STATUS_UNUSED]
        );
    }

    /**
     * Bind a Cortex job id to the active unused token for a course.
     *
     * @param int $courseid
     * @param string $jobid
     */
    public static function bind_job(int $courseid, string $jobid): void {
        global $DB;
        $token = $DB->get_record(self::TABLE, [
            'courseid' => $courseid,
            'status' => self::STATUS_UNUSED,
        ], '*', IGNORE_MULTIPLE);
        if ($token) {
            $token->jobid = $jobid;
            $DB->update_record(self::TABLE, $token);
        }
    }

    /**
     * Look up a token row by its raw value.
     *
     * @param string $raw
     * @return \stdClass|null
     */
    public static function lookup(string $raw): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['tokenhash' => self::hash($raw)]);
        return $record ?: null;
    }

    /**
     * Whether a token row is past its expiry time.
     *
     * @param \stdClass $token
     * @return bool
     */
    public static function is_expired(\stdClass $token): bool {
        return (int)$token->timeexpires < time();
    }

    /**
     * Mark a token as used (burned).
     *
     * @param \stdClass $token
     */
    public static function mark_used(\stdClass $token): void {
        global $DB;
        $token->status = self::STATUS_USED;
        $token->timeused = time();
        $DB->update_record(self::TABLE, $token);
    }

    /**
     * Mark a token as expired.
     *
     * @param \stdClass $token
     */
    public static function mark_expired(\stdClass $token): void {
        global $DB;
        $token->status = self::STATUS_EXPIRED;
        $DB->update_record(self::TABLE, $token);
    }
}
