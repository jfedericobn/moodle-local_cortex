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
 * HMAC-SHA256 verification for Cortex callbacks.
 *
 * Cortex signs and transmits the exact same bytes (a compact, key-sorted JSON
 * document) so verification is performed over the raw request body without any
 * re-encoding. See cortex/jobs/callbacks.py::sign_callback_payload.
 *
 * @package    local_cortex
 * @copyright  2026 onwards, Blindside Networks Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */
class hmac {
    /**
     * Compute the hex HMAC-SHA256 signature of a raw body.
     *
     * @param string $rawbody Exact request body bytes.
     * @param string $secret Shared signing secret.
     * @return string Lowercase hex digest.
     */
    public static function sign(string $rawbody, string $secret): string {
        return hash_hmac('sha256', $rawbody, $secret);
    }

    /**
     * Constant-time verification of a provided signature against the raw body.
     *
     * @param string $rawbody Exact request body bytes.
     * @param string $signature Provided hex signature (X-Cortex-Signature).
     * @param string $secret Shared signing secret.
     * @return bool
     */
    public static function verify(string $rawbody, string $signature, string $secret): bool {
        if ($secret === '' || $signature === '') {
            return false;
        }
        $expected = self::sign($rawbody, $secret);
        return hash_equals($expected, $signature);
    }
}
