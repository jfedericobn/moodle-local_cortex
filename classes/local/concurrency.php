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

use core\lock\lock;
use core\lock\lock_config;

/**
 * Site-wide concurrency limiter for Cortex submits, implemented as a counting
 * semaphore over N named Moodle locks.
 *
 * @package    local_cortex
 * @copyright  2026 onwards, Blindside Networks Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */
class concurrency {
    /** @var string Lock factory type. */
    const LOCK_TYPE = 'local_cortex_submit';

    /**
     * Try to acquire one of the available submit slots without blocking.
     *
     * @return lock|null A held lock (release when done) or null if all slots are busy.
     */
    public static function acquire(): ?lock {
        $max = config::max_concurrent();
        $factory = lock_config::get_lock_factory(self::LOCK_TYPE);
        for ($slot = 0; $slot < $max; $slot++) {
            $lock = $factory->get_lock('slot_' . $slot, 0);
            if ($lock) {
                return $lock;
            }
        }
        return null;
    }
}
