#!/usr/bin/env node
/**
 * The JS twins of the day-and-clock helpers answer exactly what PHP answers (TIME-ZONE-JS-01).
 *
 * The table is Rsx_Time_Zone_Helpers_Test's: an evening instant's local day and clock, a date
 * and clock combined, both daylight-saving days of 2026 in America/Chicago - in a browser
 * running in UTC, so nothing comes from the device's own zone.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

const CASES = [
    ['local_date', ['2026-10-27T04:00:00Z', 'America/Chicago'], '2026-10-26'],
    ['local_clock', ['2026-10-27T04:00:00Z', 'America/Chicago'], '23:00'],
    ['combine_date_and_time', ['2026-10-26', '23:00', 'America/Chicago'], '2026-10-27T04:00:00.000Z'],
    ['combine_date_and_time', ['2026-10-26', '23:00:30', 'America/Chicago'], '2026-10-27T04:00:30.000Z'],
    ['combine_date_and_time', ['2026-10-26', null, 'America/Chicago'], null],
    ['combine_date_and_time', ['2026-03-08', '02:30', 'America/Chicago'], '2026-03-08T08:30:00.000Z'],
    ['combine_date_and_time', ['2026-03-08', '10:00', 'America/Chicago'], '2026-03-08T15:00:00.000Z'],
    ['combine_date_and_time', ['2026-11-01', '01:30', 'America/Chicago'], '2026-11-01T06:30:00.000Z'],
    ['day_start_utc', ['2026-11-01', 'America/Chicago'], '2026-11-01T05:00:00.000Z'],
    ['day_after_utc', ['2026-11-01', 'America/Chicago'], '2026-11-02T06:00:00.000Z'],
];

async function main() {
    const { browser, page, errors } = await open_sys_page({ timezone_id: 'UTC' });
    try {
        const results = await page.evaluate((cases) => cases.map(([fn, args]) => Rsx_Time[fn](...args)), CASES);
        CASES.forEach(([fn, args, expected], i) => {
            const label = fn + '(' + args.map((a) => JSON.stringify(a)).join(', ') + ')';
            if (results[i] === expected) {
                console.log('PASS: ' + label + ' = ' + JSON.stringify(expected));
            } else {
                console.log('FAIL: zone helpers - ' + label + ' expected ' + JSON.stringify(expected) + ', got ' + JSON.stringify(results[i]));
                process.exitCode = 1;
            }
        });
        if (errors.length) {
            console.log('FAIL: zone helpers - page errors ' + JSON.stringify(errors));
            process.exitCode = 1;
        }
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.log('FAIL: zone helpers - ' + (err && err.stack ? err.stack : err));
    process.exitCode = 1;
});
