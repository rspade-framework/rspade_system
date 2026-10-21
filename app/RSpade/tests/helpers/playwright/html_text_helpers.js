#!/usr/bin/env node
/**
 * nl2brhtml() and html_to_text() - the two JS helpers for moving between plain text and HTML.
 *
 *   nl2brhtml(str)      escapes, then turns every line break into <br />; '' for null
 *   html_to_text(html)  the text a browser shows for the HTML, parsed INERTLY: an <img> in it
 *                       is never fetched
 *
 * The inertness is the point of html_to_text(): the idiom it replaces,
 * $('<div>').html(x).text(), builds elements owned by the live document, and the browser
 * fetches their images while parsing. The test watches the network for the probe image.
 *
 * EVERYTHING IT TOUCHES IS THE FRAMEWORK'S: the page is the control panel at /_sys.
 *
 * Exit 0 on pass, 1 on any failure. Prints PASS:/FAIL: lines.
 */
const { open_sys_page } = require('../../_lib/playwright/sys_page.js');

const NAME = 'nl2brhtml / html_to_text';
const PROBE_PATH = '/__html_to_text_probe.png';

async function run() {
    const { browser, page, errors, on_request } = await open_sys_page();

    let probe_requested = false;
    on_request(async (route, request) => {
        if (request.url().includes(PROBE_PATH)) {
            probe_requested = true;
            await route.abort();
            return true;
        }
        return false;
    });

    try {
        const result = await page.evaluate((probe_path) => {
            const markup = '<p>Fish &amp; chips</p><img src="' + probe_path + '"><p>&lt;b&gt;</p>';
            return {
                nl_basic: nl2brhtml('a<b>\nc & d'),
                nl_crlf: nl2brhtml('x\r\ny'),
                nl_null: nl2brhtml(null),
                nl_undefined: nl2brhtml(undefined),
                nl_number: nl2brhtml(42),
                h2t_markup: html_to_text(markup),
                h2t_entities: html_to_text('{&quot;a&quot;:&quot;&lt;x&gt;&quot;}'),
                h2t_empty_quill: html_to_text('<p><br></p>').trim(),
                h2t_null: html_to_text(null),
                dhe_json: decode_html_entities('{&quot;name&quot;:&quot;A<B Co &amp; Sons&quot;}'),
                dhe_null: decode_html_entities(null),
            };
        }, PROBE_PATH);

        // Give an image request the chance to leave, had one been made.
        await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));

        const checks = [
            ['nl2brhtml escapes and breaks lines', result.nl_basic === 'a&lt;b&gt;<br />\nc &amp; d'],
            ['nl2brhtml keeps a CRLF as one break', result.nl_crlf === 'x<br />\r\ny'],
            ['nl2brhtml(null) is empty', result.nl_null === ''],
            ['nl2brhtml(undefined) is empty', result.nl_undefined === ''],
            ['nl2brhtml stringifies a number', result.nl_number === '42'],
            ['html_to_text drops tags and decodes entities', result.h2t_markup === 'Fish & chips<b>'],
            ['html_to_text decodes an entity-encoded JSON string', result.h2t_entities === '{"a":"<x>"}'],
            ['html_to_text reads an emptied editor as empty', result.h2t_empty_quill === ''],
            ['html_to_text(null) is empty', result.h2t_null === ''],
            ['decode_html_entities() decodes entities and keeps a literal <', result.dhe_json === '{"name":"A<B Co & Sons"}'],
            ['decode_html_entities(null) is empty', result.dhe_null === ''],
            ['html_to_text fetched no image', probe_requested === false],
            ['no page errors', errors.length === 0],
        ];

        for (const [label, ok] of checks) {
            if (ok) {
                console.log('PASS: ' + NAME + ' - ' + label);
            } else {
                console.log('FAIL: ' + NAME + ' - ' + label);
                process.exitCode = 1;
            }
        }

        if (process.exitCode) {
            console.log('  results: ' + JSON.stringify(result));
        }
        if (errors.length) {
            console.log('  page errors: ' + JSON.stringify(errors));
        }
    } finally {
        await browser.close();
    }
}

run().catch((e) => {
    console.log('FAIL: ' + NAME + ' - ' + e.message);
    process.exit(1);
});
