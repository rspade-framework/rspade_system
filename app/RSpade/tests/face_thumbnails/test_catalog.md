# Test Catalog: face_thumbnails

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| FACE-01 | The crop puts the face 40% down and clamps into the image | php | 100x100 source, 100x50 box, face y 0.10 / 0.60 / 0.90 | rows 0-50 / 40-90 / 50-100 | implemented | 2026-11-17 |
| FACE-02 | The crop centres the face horizontally and clamps at both edges | php | 100x100 source, 50x100 box, face x 0.5 / 0.1 / 0.9 / 0.4 | columns 25 / 0 / 50 / 15 | implemented | 2026-11-17 |
| FACE-03 | Exit 0 + found / none / failed is an answer; stderr never changes the verdict | php | `_judge_run()` | found x,y / none / failed | implemented | 2026-11-17 |
| FACE-04 | Every other run is unavailable and its reason carries the evidence | php | exit 3, exit 1, garbage, empty, two lines, unknown status, out-of-range centre, no exit code | unavailable, reason names it, stderr quoted | implemented | 2026-11-17 |
| FACE-05 | Only face=1 / true (any case) asks for a face | php | `_face_requested()` | true / false | implemented | 2026-11-17 |
| FACE-06 | The health row is OK (with the OpenCV version) only for a clean none; WARN with the remedy otherwise | php | `_health_row()` | OK / WARN | implemented | 2026-11-17 |
| FACE-10 | A found face is stored, the crop is cut around it, cached under the face key only; the detector runs once | php | stub found x=1/6 on a red/green/blue band image | face_x 0.1667, red centre, a week, face key only | implemented | 2026-11-17 |
| FACE-11 | No face stores -1,-1 and serves the plain cover under the plain key | php | stub none | -1/-1, green centre, a week, plain key only, one run | implemented | 2026-11-17 |
| FACE-12 | "failed" from a working detector is stored as no face | php | stub failed + stderr | -1/-1, green centre, a week | implemented | 2026-11-17 |
| FACE-13 | Exit 3 is unavailable: nothing stored, plain bytes, max-age 1800 (also from the cache), plain key only, a warning | php | stub exit 3 | NULL/NULL, green, 1800, no face key, Log::warning | implemented | 2026-11-17 |
| FACE-14 | Another exit code is unavailable | php | stub exit 1 + traceback | as FACE-13, reason quotes stderr | implemented | 2026-11-17 |
| FACE-15 | Garbage on stdout is unavailable | php | stub exit 0 + non-JSON | as FACE-13 | implemented | 2026-11-17 |
| FACE-16 | A missing python is unavailable | php | config python path absent | as FACE-13 | implemented | 2026-11-17 |
| FACE-17 | A stored position is used without running the detector | php | record_face_position + stub that would answer none | red centre, zero runs | implemented | 2026-11-17 |
| FACE-18 | fit and non-images never run the detector | php | fit image + txt with face=1 | nothing stored, zero runs | implemented | 2026-11-17 |
| FACE-19 | The PHP builder adds face=1 to a cover URL only, after ?v= | php | `get_thumbnail_url()` cover / width-only cover / fit / no face | face=1 / face=1 / none / none | implemented | 2026-11-17 |
| FACE-23 | A width-only dynamic request is cached and drawn apart from the same width and height | php | fit/50/50 then fit/50 of a 600x200 image | distinct names with _{hash}_ and _r{N}; 100x100 / 100x34; both cached | implemented | 2026-11-17 |
| FACE-20 | An EXIF orientation-6 JPEG is thumbnailed upright | php | 60x40 JPEG, left red / right blue, orientation 6 | 40x60 portrait, red top, blue bottom | implemented | 2026-11-17 |
| FACE-22 | A thumbnail carries no metadata: no orientation tag to double-rotate it, no GPS | php | orientation-6 JPEG with a GPS block, cover 30x30 | no EXIF/XMP chunk, no exif:* property | implemented | 2026-11-17 |
| FACE-21 | The real detector answers none on a face-free synthetic image, and the health row is OK | php | `/opt/rspade/face_detection` | none, OK | implemented (skips where the detector is not installed) | 2026-11-17 |
| FACE-30 | The real detector finds a real face and the crop lands on it | php | a committed photo | found | deferred: needs a licensed face photo committed as a fixture | 2026-11-17 |
