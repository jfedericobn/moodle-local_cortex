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

use local_cortex\local\hmac;

/**
 * Tests for HMAC callback verification.
 *
 * @package    local_cortex
 * @covers     \local_cortex\local\hmac
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class hmac_test extends \advanced_testcase {
    /**
     * Golden vector produced by Cortex's sign_callback_payload
     * (json.dumps(payload, sort_keys=True, separators=(",", ":")) + HMAC-SHA256).
     *
     * @var string
     */
    const GOLDEN_BODY = '{"corpus_id":"corpus-001","error_code":null,"error_message":null,'
        . '"job_id":"job-abc-123","reference_code":"REF-XYZ-789","sent_at":"2026-07-13T20:40:00+00:00",'
        . '"stage":"completed","state":"ready","tenant_id":"tenant-demo"}';

    /** @var string */
    const GOLDEN_SECRET = 'test-shared-secret-123';

    /** @var string */
    const GOLDEN_SIGNATURE = 'b502d585a3099b1185e0b6f3fc66e5ff289b882e38f3a6726de82019f97a6e2e';

    /**
     * The Moodle verifier must accept a genuine Cortex signature over the raw body.
     */
    public function test_accepts_cortex_golden_vector(): void {
        $this->assertTrue(hmac::verify(self::GOLDEN_BODY, self::GOLDEN_SIGNATURE, self::GOLDEN_SECRET));
    }

    /**
     * PHP re-serialisation of the decoded payload (sorted keys, compact) must
     * reproduce Cortex's canonical body byte-for-byte.
     */
    public function test_php_reproduces_canonical_body(): void {
        $decoded = json_decode(self::GOLDEN_BODY, true);
        ksort($decoded);
        $canonical = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertSame(self::GOLDEN_BODY, $canonical);
    }

    /**
     * A tampered body must fail verification.
     */
    public function test_rejects_tampered_body(): void {
        $tampered = str_replace('ready', 'failed', self::GOLDEN_BODY);
        $this->assertFalse(hmac::verify($tampered, self::GOLDEN_SIGNATURE, self::GOLDEN_SECRET));
    }

    /**
     * A wrong secret must fail verification.
     */
    public function test_rejects_wrong_secret(): void {
        $this->assertFalse(hmac::verify(self::GOLDEN_BODY, self::GOLDEN_SIGNATURE, 'not-the-secret'));
    }

    /**
     * Empty signature or secret must fail closed.
     */
    public function test_rejects_empty_inputs(): void {
        $this->assertFalse(hmac::verify(self::GOLDEN_BODY, '', self::GOLDEN_SECRET));
        $this->assertFalse(hmac::verify(self::GOLDEN_BODY, self::GOLDEN_SIGNATURE, ''));
    }

    /**
     * Round-trip: signing with the Moodle helper matches independent verification.
     */
    public function test_sign_then_verify_roundtrip(): void {
        $body = '{"a":"1","b":"2"}';
        $secret = 'roundtrip-secret';
        $sig = hmac::sign($body, $secret);
        $this->assertTrue(hmac::verify($body, $sig, $secret));
    }
}
