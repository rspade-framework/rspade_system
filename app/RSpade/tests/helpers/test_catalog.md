# Test catalog: helpers

Status legend: `implemented` | `deferred` | `blocked` | `planned`.
Type: php. Last updated: 2026-10-11.

## Bytes_To_Human_Test (php, no DB)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| helpers-01 | units climb by 1024 while the value EXCEEDS 1024 (current boundary pinned) | 0, 1023, 1024, 1025, 1048576, 1048577 | `0 B`, `1023 B`, `1024 B`, `1 KB`, `1024 KB`, `1 MB` | implemented |
| helpers-02 | the ladder continues to GB and stops at PB | 5 GiB, 3 x 1024^6 | `5 GB`, `3072 PB` | implemented |
| helpers-03 | precision rounds, default 2 | 1587, 1587 at precision 1 | `1.55 KB`, `1.5 KB` | implemented |
| helpers-04 | numeric strings format; non-numeric input answers `---` | `'2048'`, `'abc'`, null | `2 KB`, `---`, `---` | implemented |

## Rsx_Qr_Code_Test (php, no DB)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| helpers-qr-01 | the result carries the SVG and both recommended sizes | a short URL | keys `svg, size_px, size_in, modules`; 37 modules, 148 px, 1.0 in | implemented |
| helpers-qr-02 | the SVG is inline-embeddable and states its own screen size | a short URL | starts `<svg`, ends `</svg>`, no `<?xml`, viewBox and width/height match | implemented |
| helpers-qr-03 | the code is black on an opaque white ground | a short URL | full-size `#fff` rect, `#000` path, no `currentColor` | implemented |
| helpers-qr-04 | the quiet zone is empty and the three finder patterns are drawn | modules read back from the path | nothing within 4 modules of an edge; ring, light ring and core at each corner | implemented |
| helpers-qr-05 | error correction is level Q | 32 and 33 byte-mode characters | 29-module then 33-module symbol | implemented |
| helpers-qr-06 | recommended sizes grow with the text | a long string | 4 px and 0.02 in per module, above the one-inch minimum | implemented |
| helpers-qr-07 | text outside ASCII is encoded | accented and CJK text | a drawn code | implemented |
| helpers-qr-08 | empty text is refused | `''` | InvalidArgumentException | implemented |

## nl2brhtml / html_to_text (`playwright/html_text_helpers.js`)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| helpers-h2t-01 | `nl2brhtml()` escapes and turns each line break (LF, CRLF) into `<br />`; null/undefined give '' | `'a<b>\nc & d'`, `'x\r\ny'`, null, undefined, 42 | `'a&lt;b&gt;<br />\nc &amp; d'`, `'x<br />\r\ny'`, '', '', '42' | implemented |
| helpers-h2t-02 | `html_to_text()` drops tags and decodes entities | markup, an entity-encoded JSON string, `<p><br></p>`, null | the text; the decoded JSON; ''; '' | implemented |
| helpers-h2t-03 | `html_to_text()` parses inertly - an `<img>` in the input is never fetched | markup with an `<img>` at a watched URL | no request for it | implemented |
| helpers-h2t-04 | PHP `nl2brhtml()` escapes and breaks lines like the JS twin; null gives '' (`php/Nl2brhtml_Test.php`) | the same LF and CRLF strings; null; an `<img>` | identical output; ''; no `<img` | implemented |
