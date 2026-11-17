# Concern: face_thumbnails (face-centred cover thumbnails)

## Domain

A dynamic cover thumbnail of an image may be cut around the face in it instead of around the
middle (`?face=1` on `/_thumbnail/dynamic/:key/cover/:w/:h`). The face is found once per
distinct image by a detector in the RSpade container image (OpenCV's YuNet model through
`resource/docker/face_detection/detect_face.py`) and its centre is stored on the blob
(`_file_storage.face_x` / `face_y`):

- `x, y` - the face centre, fractions of the UPRIGHT image (EXIF orientation applied);
- `-1, -1` - a working detector found no face, or could not read the image;
- `NULL, NULL` - not checked yet, or the detector was unavailable when it was asked.

A detector that is missing, cannot load OpenCV or the model (exit 3), or malfunctions (any
other exit code, a missing or unparseable stdout line) stores NOTHING: the request is served
the plain cover, cached under the plain key only, with `Cache-Control: private, max-age=1800`,
and `Log::warning('Face detection unavailable: ...')` names the reason. A run is judged only by
its exit code and its stdout line; stderr is quoted in the warning, never judged.

The detector's OpenCV reads JPEG and PNG; any other image (HEIC, TIFF, GIF, WebP) is handed
to it as an upright JPEG copy ImageMagick draws (`Face_Detection::_upright_jpeg_copy()`),
deleted after the run - covered by the `media_metadata` concern.

Only a cover thumbnail of an image drawn from the blob itself consults the detector: `fit`,
documents, SVGs and renditions ignore `face`.

Every thumbnail is drawn upright (Imagick `autoOrient()` before any scaling) and carries no
metadata (EXIF, GPS, XMP and IPTC stripped; only the ICC colour profile is kept). Cache
filenames carry the renderer revision (`_r{N}`, `THUMBNAIL_RENDER_REVISION`), so thumbnails
cached by an earlier renderer are never served again.

## Source

- `Core/Files/Face_Detection.php` - `face_center()`, `detect()`, `_judge_run()`, `crop_offset()`,
  the `Face Detection` health row.
- `Core/Files/File_Attachment_Controller.php` - `thumbnail()`, `_face_requested()`,
  `__generate_and_serve_thumbnail()`, `__generate_thumbnail()`, the cache filename helpers and
  `THUMBNAIL_RENDER_REVISION`.
- `Core/Files/File_Storage_Model_Abstract.php` - `face_position()`, `record_face_position()`.
- `Core/Files/File_Attachment_Model_Abstract.php` - `get_thumbnail_url(..., $face)`;
  `Core/Files/File_Attachment_Model.js` - `thumbnail_url(record, {face})`;
  `Core/Preview/Attachment_Thumbnail.jqhtml` - `$face`.
- `system/config/rsx.php` - `rsx.thumbnails.face_detection` (`python`, `script`, `model`).
- `database/migrations/2026_11_17_064514_add_face_position_to_file_storage.php`.

Man page: `rsx:man thumbnails`; health rows: `rsx:man health`.

## Test fixtures

`resource/stub_detector.py` stands in for the detector. It is invoked exactly as the real one
(`[python, script, model, image]`); the MODEL argument names a JSON spec the test wrote
(`exit`, `stdout`, `stderr`, `log`), and every call is appended to the `log` file so a test can
prove the detector did or did not run.

The real detector is exercised only where it is installed (`/opt/rspade/face_detection`, the
RSpade image the `rsx:test` sibling containers are built from); elsewhere that test skips and
says why.

## Testable surface

- php: the crop arithmetic, the run verdicts, the `face` query parsing, the health row;
  through the dynamic route with the stub - each ruling's storage, bytes, Cache-Control and
  cache files; a stored answer reused; `fit` and non-images never detecting; an EXIF-rotated
  JPEG drawn upright and stripped of its orientation tag and GPS block; the real detector on a face-free synthetic image.
