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
 * Poll Cortex course ingestion status via AJAX.
 *
 * @module     local_cortex/status_poll
 * @copyright  2026 onwards, Blindside Networks Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';

const SELECTOR = '[data-region="local-cortex-status"]';
const DEFAULT_INTERVAL_MS = 5000;

/**
 * Fetch status and update the status panel.
 *
 * @param {HTMLElement} root
 * @returns {Promise<Object>}
 */
const refresh = (root) => {
    const courseid = parseInt(root.dataset.courseid, 10);
    if (!courseid) {
        return Promise.resolve(null);
    }

    return Ajax.call([{
        methodname: 'local_cortex_get_course_status',
        args: {courseid},
    }])[0].then((data) => {
        if (!data) {
            return null;
        }
        root.innerHTML = data.html;
        root.dataset.status = data.status;
        root.dataset.poll = data.poll ? '1' : '0';
        return data;
    }).catch(Notification.exception);
};

/**
 * Start polling while status is queued/processing.
 *
 * @param {Object} config
 * @param {Number} config.courseid
 * @param {Number} [config.intervalms]
 */
export const init = (config) => {
    const courseid = config.courseid;
    const intervalms = config.intervalms || DEFAULT_INTERVAL_MS;
    const root = document.querySelector(`${SELECTOR}[data-courseid="${courseid}"]`);
    if (!root || root.dataset.initialised === '1') {
        return;
    }
    root.dataset.initialised = '1';

    let timer = null;

    const stop = () => {
        if (timer) {
            window.clearInterval(timer);
            timer = null;
        }
    };

    const tick = () => {
        refresh(root).then((data) => {
            if (!data || !data.poll) {
                stop();
            }
        });
    };

    if (root.dataset.poll === '1') {
        timer = window.setInterval(tick, intervalms);
        // Immediate refresh so a just-queued resync clears stale fields quickly.
        tick();
    }
};
