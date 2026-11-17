# Test Catalog: media_metadata

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| MEDIA-01 | Orientations 5-8 swap an image's size; 0-4 do not | php | `upright_size(60, 40, n)` | 60x40 / 40x60 | implemented | 2026-11-17 |
| MEDIA-02 | A quarter-turn video rotation swaps; SAR scales the width first | php | `displayed_video_size()` | table | implemented | 2026-11-17 |
| MEDIA-03 | ffprobe runs are judged: rotation from side data or tag, container duration first, audio-only, failures | php | `_judge_probe()` | ok / unreadable | implemented | 2026-11-17 |
| MEDIA-04 | The ffprobe health row: FAIL missing or not running, OK with the binary | php | `_availability_row()` | FAIL / OK | implemented | 2026-11-17 |
| MEDIA-05 | ffprobe and HEIC decoding are healthy in the RSpade image | php | the two health rows | OK | implemented | 2026-11-17 |
| GPS-01 | The GPS directory is removed, the TIFF keeps its length, later IFD0 entries move up, the GPS bytes are zeros | php | II and MM TIFFs | 3 entries, zeros | implemented | 2026-11-17 |
| GPS-02 | A JPEG loses its position and keeps orientation, camera fields and image data | php | JPEG with EXIF + GPS | no exif:GPS*, orientation 6, Make kept | implemented | 2026-11-17 |
| GPS-03 | PNG eXIf (CRC patched) and WebP EXIF chunks are stripped | php | Imagick-written files with an EXIF profile | latitude gone, readable | implemented | 2026-11-17 |
| GPS-04 | A HEIC Exif item is stripped in place and still decodes | php | `sideways_with_gps.heic` | GPS field gone, 40x60 | implemented | 2026-11-17 |
| GPS-05 | Patches applied chunk by chunk (as a stream) equal the whole edit | php | chunk sizes 1, 7, 64, 4096 | identical | implemented | 2026-11-17 |
| GPS-06 | The stored blob keeps its bytes and hash, and get_gps_position() reads the coordinates | php | JPEG with N/W position | sha256(uploaded); 37.774917, -122.419389; null for no GPS / text | implemented | 2026-11-17 |
| GPS-07 | /_inline, /_download, download_response(), inline_response() serve the image without GPS, same length, orientation kept, still hardened and private | php | JPEG with GPS | stripped bytes | implemented | 2026-11-17 |
| GPS-08 | A HEIC is served without its GPS field | php | `sideways_with_gps.heic` | stripped bytes | implemented | 2026-11-17 |
| GPS-09 | Files without GPS (plain JPEG, EXIF with only orientation, text) are served byte for byte as file responses | php | three files | BinaryFileResponse, identical | implemented | 2026-11-17 |
| GPS-10 | `rsx.files.strip_gps_when_served = false` serves the original as stored | php | JPEG with GPS | identical | implemented | 2026-11-17 |
| GPS-11 | A /_download_zip member is withheld its position (CRC over the served bytes) | php | Zip_Stream with the serving patches | ZipArchive reads the stripped image | implemented | 2026-11-17 |
| GPS-12 | Malformed EXIF is served as stored, with a warning | php | IFD0 offset past the end | throws / [] + Log::warning | implemented | 2026-11-17 |
| DIMS-01 | An orientation-6 JPEG stores its upright size | php | 60x40 JPEG, orientation 6 | 40x60 | implemented | 2026-11-17 |
| DIMS-02 | A NULL size is read on demand by fetch() and stored without moving updated_at | php | cleared row | 40x60 stored, updated_at unchanged | implemented | 2026-11-17 |
| DIMS-03 | A rotated video stores its displayed size and duration, at upload and on demand | php | `rotated_90.mp4` | VIDEO, 32x64, 2 s | implemented | 2026-11-17 |
| DIMS-04 | A missing ffprobe stores nothing, logs, keeps the video a video; a later read fills it | php | ffprobe path absent | NULLs + warning, then 32x64, 2 s | implemented | 2026-11-17 |
| DIMS-05 | The migration clears image, animated image and video rows only | php | five types with 11/12/13 | NULL x3 / untouched | implemented | 2026-11-17 |
| DIMS-06 | The reclassify migration restores a video demoted to OTHER + preview_unavailable to VIDEO with its metadata empty, and touches nothing else | php | demoted video, video, demoted image, binary | VIDEO, flag 0, NULL x3, then 32x64 2 s on demand / untouched | implemented | 2026-11-17 |
| HEIC-01 | A HEIC is an image, measured upright | php | `sideways_with_gps.heic` | image/heic, IMAGE, 40x60 | implemented | 2026-11-17 |
| HEIC-02 | A HEIC thumbnail is drawn upright | php | dynamic fit 40x40 | WebP 40x60, red over blue | implemented | 2026-11-17 |
| HEIC-03 | A HEIC previews as a drawn WebP (urls.display) and its inline URL stays the original; PNG shows itself; non-images have none | php | `get_preview_info()` | thumbnail URL / get_url() / null | implemented | 2026-11-17 |
| HEIC-04 | The detector copy of a HEIC is an upright JPEG with no orientation tag | php | `_upright_jpeg_copy()` | JPEG 40x60, red over blue | implemented | 2026-11-17 |
| HEIC-05 | The detector is handed a JPEG copy of a HEIC, deleted after; a JPEG is handed over as it is | php | stub detector | .jpg path, gone; original path | implemented | 2026-11-17 |
| HEIC-06 | The real detector reads a HEIC through the copy | php | `/opt/rspade/face_detection` | none (not failed) | implemented (skips where the detector is not installed) | 2026-11-17 |
| HEIC-10 | A real iPhone HEIC (HEVC grid tiles, irot, depth/thumbnail items) measures, thumbnails and strips correctly | php | a committed camera HEIC | upright, GPS gone | deferred: needs a licensed camera photo committed as a fixture | 2026-11-17 |
| DIMS-10 | A real phone video (rotation -90, H.264/HEVC) measures upright | php | a committed camera clip | portrait size | deferred: needs a licensed camera clip committed as a fixture | 2026-11-17 |
