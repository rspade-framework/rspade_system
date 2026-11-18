# Test catalog: url_fetch

Status legend: `implemented` | `deferred` (reason) | `blocked` (see issues) | `planned`.

## Url_Fetch_Address_Class_Test (php, no database) - the classifier

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| UF-01 | Every IPv4 non-public range is classified | php | one or two addresses per range | loopback / unspecified / private / link-local / CGNAT / multicast / broadcast / reserved | implemented | 2026-11-18 |
| UF-02 | The public neighbours of each range are public | php | 172.15.255.255, 100.128.0.1, 169.253.255.255, ... | null | implemented | 2026-11-18 |
| UF-03 | IPv6 ranges are classified (bracketed too) | php | ::1, ::, fc00::/7, fe80::/10, ff00::/8, 2001:db8:: | as named | implemented | 2026-11-18 |
| UF-04 | IPv6 forms embedding IPv4 answer for the embedded address | php | ::ffff:127.0.0.1, ::ffff:a00:1, 64:ff9b::10.0.0.1, 2002:7f00:1:: | loopback / private | implemented | 2026-11-18 |
| UF-05 | A non-address is reserved | php | 'not-an-ip' | reserved | implemented | 2026-11-18 |
| UF-06 | check_destination() refuses private literal hosts, honours allow_private | php | 127.0.0.1, [::1], 169.254.169.254, mapped, 10.x | refused_destination; allowed with the flag | implemented | 2026-11-18 |

## Url_Fetch_Test (php, default isolation) - live against the fixture server

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| UF-10 | A loopback URL is refused, naming the host, leaving no temp file | php | fixture URL, policy on | refused_destination | implemented | 2026-11-18 |
| UF-11 | A redirect from an allowed host to a private one is refused at the second hop | php | Loopback_Public fixture, 302 to 169.254.169.254 | refused_destination | implemented | 2026-11-18 |
| UF-12 | Non-http schemes are refused | php | ftp, file, gopher, relative | invalid_url | implemented | 2026-11-18 |
| UF-13 | A declared Content-Length over the cap is refused before the body is read | php | small body, claim 5000, cap 100 | too_large "declares"; no temp file | implemented | 2026-11-18 |
| UF-14 | A body over the cap aborts mid-stream and deletes the file; exactly the cap is accepted | php | 400000 streamed, cap 100000 | too_large; size 100000 accepted | implemented | 2026-11-18 |
| UF-15 | The result carries path, type, Content-Disposition name, final URL across a relative redirect | php | /redirect?to=/named | report.csv, text/csv, 8 bytes | implemented | 2026-11-18 |
| UF-16 | HTTP error status and an endless redirect fail loudly | php | 404, /loop | http_status 404; too_many_redirects | implemented | 2026-11-18 |
| UF-17 | A temp file left in place is deleted when its process ends | php | resource/fetch_and_exit.php subprocess | existed during, gone after | implemented | 2026-11-18 |
| UF-18 | create_from_url() files the download, explicit filename wins, no temp file left | php | /pixel.png, /named | image/png row; chosen.csv | implemented | 2026-11-18 |
| UF-19 | create_from_url() refuses a private destination storing nothing; max_bytes reaches the fetch | php | policy on; max_bytes 100 | refused, no row; too_large | implemented | 2026-11-18 |
| UF-20 | The connect limit expires into a loud error naming the URL | php | a host that drops SYNs | connect | deferred (no SYN-dropping host on the box) | 2026-11-18 |
