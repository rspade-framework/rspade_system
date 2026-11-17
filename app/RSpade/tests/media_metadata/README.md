# Concern: media_metadata (displayed sizes, GPS withheld when served, HEIC)

## Domain

What the framework reads from, and removes from, an uploaded photo or video.

- **Displayed size.** `_file_attachments.width` / `height` are the size a picture is SEEN at:
  an image's EXIF orientations 5-8 swap them (the rule the thumbnailer's `autoOrient()`
  applies), a video's quarter-turn rotation swaps them (ffprobe: the display matrix side data,
  else the `rotate` tag; a non-square sample aspect ratio scales the width first). A video's
  `duration` is ffprobe's, in whole seconds.
- **On demand.** NULL means "not read yet". `File_Attachment_Model::ensure_media_metadata()`
  reads and stores them the first time a payload carries them (`fetch()`, the `/_upload`
  response, the Files API), as one raw single-row update under `Site_Lock::write_exception()`
  that holds `updated_at` (the three columns are `revision_exclude`). An SVG, an attachment
  with no resident bytes and a degraded upload owe nothing. A failed read stores nothing, logs
  a warning, and is not retried in the same process.
- **ffprobe missing.** An upload stores no size or duration (logged), the video stays a video,
  and the next read after ffprobe is installed fills them in. rsx:health "ffprobe" FAILs.
- **The migration** `clear_stored_media_dimensions_on_file_attachments` clears width / height
  / duration on file types 1, 2, 3 (image, animated image, video) only.
- **GPS withheld when served** (`rsx.files.strip_gps_when_served`, on by default). The stored
  original is never changed (bytes, hash, deduplication as uploaded); every route handing a
  client the original's bytes - `/_inline`, `/_download`, `/_download_zip` members,
  `File_Storage_Model::download_response()` / `inline_response()` - streams an image that
  carries GPS with its GPS directory removed in place (`Exif_Gps::patches_for_file()` +
  `apply_patches()`: same length, orientation and other EXIF kept, nothing re-encoded). JPEG,
  PNG (CRC patched), WebP, HEIC/HEIF/AVIF (the Exif item through iinf/iloc), TIFF. Every other
  file is a plain file response, byte for byte. Malformed EXIF: served as stored, with a
  warning. Server code reads the position with `get_gps_position()` (`gps_position()` on the
  blob, `Exif_Gps::read_position()`).
- **HEIC.** Classified as an image (libmagic sniffs `image/heic`), decoded upright by libheif
  (needs `libheif-plugin-libde265`; rsx:health "HEIC Decoding"), thumbnailed upright. Not in
  `rsx.preview.browser_inline`, so `can_open_inline()` is false and `get_display_url()` - what
  `Image_Viewer` shows, `urls.display` of the preview info - is a large fit thumbnail. The face
  detector is handed an upright JPEG copy (`Face_Detection::_upright_jpeg_copy()`), deleted
  after the run; JPEG and PNG are handed over as they are.

## Source

- `Core/Files/Media_Metadata.php` - `read_image()`, `upright_size()`, `probe_video()`,
  `_judge_probe()`, `displayed_video_size()`, `find_ffprobe()`, the `ffprobe` and
  `HEIC Decoding` health rows.
- `Core/Files/Exif_Gps.php` - `patches_for_file()`, `patches()`, `apply_patches()`, `strip_bytes()`,
  `strip_tiff()`, `read_position()`.
- `Core/Files/File_Storage_Model_Abstract.php` - `original_bytes_response()`,
  `gps_patches_for_serving()`, `gps_position()`; `Core/Files/Zip_Stream.php` - member patches.
- `Core/Files/File_Attachment_Model_Abstract.php` - `__process_image_or_video()`,
  `needs_media_metadata()`, `ensure_media_metadata()`, `get_display_url()`, `get_gps_position()`.
- `Core/Files/Face_Detection.php` - `detect()`, `_upright_jpeg_copy()`.
- `Core/Files/File_Preview_Controller.php` - `urls.display`; `Core/Preview/Document_Preview.jqhtml`.
- `Core/Files/File_Attachment_Controller.php` - `inline()`, `download_file()`, `download_multiple_zip()`.
- `system/config/rsx.php` - `rsx.files.strip_gps_when_served`, `rsx.attachments.ffprobe_binary_path`,
  `rsx.preview.browser_inline`.
- `database/migrations/2026_11_17_072553_clear_stored_media_dimensions_on_file_attachments.php`.
- `database/migrations/2026_11_17_082212_reclassify_degraded_videos_on_file_attachments.php` - a
  video demoted to OTHER + `preview_unavailable` before ffprobe read video metadata is a VIDEO
  again, its metadata left to be read on demand.

Man pages: `rsx:man file_upload`, `rsx:man thumbnails`, `rsx:man health`.

## Test fixtures

- `resource/sideways_with_gps.heic` - 60x40 (left red, right blue) stored with a quarter-turn
  transform, so 40x60 upright with red on top; its Exif item carries GPSLatitudeRef "N".
- `resource/rotated_90.mp4` - 64x32 MPEG-4 video, display rotation 90, 1.5 seconds.

Both run against the real tools (ImageMagick + libheif + libde265, ffprobe), present in the
RSpade image the `rsx:test` sibling containers are built from.

## Testable surface

- php: the size rules and ffprobe verdicts as pure functions; the TIFF edit byte by byte; each
  container (JPEG, PNG, WebP, HEIC); chunked patching equal to the whole edit; the stored blob
  and hash unchanged and the position readable server-side; /_inline, /_download, the storage
  response builders and a ZIP member served without GPS; files without GPS served byte for
  byte; the switch off; malformed EXIF; upright stored sizes for a JPEG, a video and a HEIC; the on-demand read
  and its write; a missing ffprobe; the migration's WHERE; the HEIC thumbnail, preview URL and
  detector copy; the real detector reading a HEIC.
