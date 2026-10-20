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
 * Thin HTTP client for the Cortex ingest plane.
 *
 * @package    local_cortex
 * @copyright  2026 onwards, Blindside Networks Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */
class ingest_client {
    /** @var int Connect timeout (seconds). */
    const CONNECT_TIMEOUT = 10;

    /** @var int Request timeout (seconds) for submit. */
    const SUBMIT_TIMEOUT = 120;

    /** @var int Request timeout (seconds) for status polls / health. */
    const READ_TIMEOUT = 15;

    /**
     * Factory.
     *
     * @return self
     */
    public static function instance(): self {
        return new self();
    }

    /**
     * Submit a source for ingestion.
     *
     * @param array $payload Full SubmitSourceRequest body.
     * @return array{httpcode:int,body:array,raw:string}
     */
    public function submit(array $payload): array {
        $url = config::ingest_url() . '/api/v1/ingest/sources';
        $curl = $this->new_curl(self::SUBMIT_TIMEOUT);
        $curl->setHeader(['Content-Type: application/json', 'Accept: application/json']);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $raw = $curl->post($url, $json);
        return $this->wrap_response($curl, $raw);
    }

    /**
     * Fetch the status of an ingestion job.
     *
     * @param string $jobid
     * @param string $tenantid
     * @return array{httpcode:int,body:array,raw:string}
     */
    public function get_job(string $jobid, string $tenantid): array {
        $url = config::ingest_url() . '/api/v1/ingest/jobs/' . rawurlencode($jobid)
            . '?tenant_id=' . rawurlencode($tenantid);
        $curl = $this->new_curl(self::READ_TIMEOUT);
        $curl->setHeader(['Accept: application/json']);
        $raw = $curl->get($url);
        return $this->wrap_response($curl, $raw);
    }

    /**
     * Lightweight connectivity probe against the ingest plane.
     *
     * @return bool True when the ingest plane responds with HTTP 200.
     */
    public function health(): bool {
        $base = config::ingest_url();
        if ($base === '') {
            return false;
        }
        $curl = $this->new_curl(self::READ_TIMEOUT);
        $curl->setHeader(['Accept: application/json']);
        $curl->get($base . '/api/v1/ingest');
        return (int)($curl->get_info()['http_code'] ?? 0) === 200;
    }

    /**
     * Build a configured Moodle curl instance.
     *
     * @param int $timeout Request timeout in seconds.
     * @return \curl
     */
    private function new_curl(int $timeout): \curl {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT,
            'CURLOPT_FOLLOWLOCATION' => 0,
        ]);
        return $curl;
    }

    /**
     * Normalise a curl response into a structured array.
     *
     * @param \curl $curl
     * @param string|bool $raw
     * @return array{httpcode:int,body:array,raw:string}
     */
    private function wrap_response(\curl $curl, $raw): array {
        $raw = is_string($raw) ? $raw : '';
        $code = (int)($curl->get_info()['http_code'] ?? 0);
        $decoded = json_decode($raw, true);
        return [
            'httpcode' => $code,
            'body' => is_array($decoded) ? $decoded : [],
            'raw' => $raw,
        ];
    }
}
