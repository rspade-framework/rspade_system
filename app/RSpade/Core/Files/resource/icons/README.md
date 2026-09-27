# File Type Icons

This directory contains icons used by the RSpade file attachment system to visually represent different file types.

## Icon Sources and Licenses

### SVG Icons (Generic Categories)

The following SVG icons are sourced from the **Papirus Icon Theme**:

- `image.svg` - Generic image files
- `video.svg` - Generic video files
- `audio.svg` - Generic audio files
- `archive.svg` - Archive/compressed files
- `text.svg` - Plain text files
- `code.svg` - Programming/code files
- `document.svg` - Document files (Word, etc.)
- `spreadsheet.svg` - Spreadsheet files (Excel, etc.)
- `presentation.svg` - Presentation files (PowerPoint, etc.)
- `file.svg` - Generic file fallback

**Source:** https://github.com/PapirusDevelopmentTeam/papirus-icon-theme
**License:** GPL-3.0
**Copyright:** PapirusDevelopmentTeam

### Custom SVG Icons

- `3d_model.svg` - 3D model files (STL, OBJ, etc.)

**Created for RSpade** - Custom wireframe cube design
**License:** MIT (same as RSpade framework)

### PNG Icons (Brand-Specific)

The following PNG icons are sourced from **Free File Icons** by Redbooth:

- `pdf.png` - Adobe PDF files
- `psd.png` - Adobe Photoshop files
- `ai.png` - Adobe Illustrator files

**Source:** https://github.com/redbooth/free-file-icons
**License:** MIT
**Copyright:** 2009 Teambox Technologies, S.L.
**Designer:** Saskia Font

## Usage

`File_Attachment_Icons` holds ONE extension map, and every extension in it names both
renditions: the colour PNG (this directory) and the outline SVG (`outline/`). An extension
it does not know gets `file.png` / `outline/file.svg`.

- Colour: `File_Attachment_Icons::get_icon_resource_by_file_extension($ext)`,
  `get_icon_as_png()`, `render_icon_as_thumbnail()`, `GET /_icon_by_extension/:ext`.
- Outline: `get_icon_resource_by_file_extension($ext, File_Attachment_Icons::STYLE_OUTLINE)`,
  `get_outline_icon_svg($ext)`, `GET /_icon_by_extension/:ext?style=outline`, and the
  `<File_Type_Icon>` component (the map and artwork are baked into every bundle).
- On an attachment: `$attachment->get_icon_resource($style)`, `$attachment->get_outline_icon_svg()`.

## Icon Format

### Colour icons

Every colour icon the framework maps and rasterises is a **PNG**, because ImageMagick is configured
to read raster coders only (its SVG coder can read local files; see
`system/app/RSpade/resource/docker/imagemagick/policy.xml` and the "ImageMagick Coder Policy"
`rsx:health` row).

- **Generic category icons** are drawn as SVG (the `.svg` files here are the source artwork)
  and shipped as 512x512 PNG rasters beside them (`file.svg` -> `file.png`).
- **Brand-specific icons** are 48x48 PNG originals.

Regenerate a raster after editing its SVG, on a box whose ImageMagick policy still permits
SVG (a workstation, not a deployed container):

```bash
convert -background none -density 768 file.svg -resize 512x512 PNG32:file.png
```

### Outline icons (`outline/`)

A square, colour-agnostic mark for a list row or a chip: Tabler's 24x24 stroke form with
`stroke="currentColor"`, `fill="none"` and nothing else, so it takes the colour of the text
around it. `width="24" height="24"` is kept as the intrinsic default size (for an `<img>`);
inline, CSS sizes it. These files are **read as text and handed to a browser verbatim** - they
are never rasterised and never reach ImageMagick, so the SVG coder policy above is unaffected.

**Source:** Tabler Icons 3.48.0 (npm `@tabler/icons`, https://tabler.io/icons), outline set
**License:** MIT - `outline/LICENSE`
**Copyright:** Pawel Kuna

Only the icons the map uses are vendored, each under its Tabler name. Normalisation drops
Tabler's `class` attribute and its invisible `M0 0h24v24H0z` bounding-box path; the drawn
elements are unchanged.

## Adding New Icons

To support a new file type:

1. Pick its colour PNG (an existing category icon, or a new one - for vector artwork, the SVG
   plus its 512px raster).
2. Pick its outline icon: Tabler's `file-type-<ext>` when one names the format, otherwise the
   closest analog in the same family. Vendor it (skip if `outline/<name>.svg` already exists):

   ```bash
   cd "$(mktemp -d)" && npm pack @tabler/icons@3.48.0 && tar xzf tabler-icons-3.48.0.tgz
   name=file-type-pdf
   perl -0777 -ne '
     my @el = grep { !/M0 0h24v24H0z/ } (/(<(?:path|circle|rect|line|polyline|polygon|ellipse)\b[^>]*\/>)/g);
     print qq{<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">\n};
     print "  $_\n" for @el;
     print "</svg>\n";
   ' "package/icons/outline/$name.svg" > "<this directory>/outline/$name.svg"
   ```

   A version bump re-runs the same command for every file in `outline/` and replaces
   `outline/LICENSE`.
3. Add ONE row to `EXTENSION_ICONS` in `File_Attachment_Icons`: `'ext' => ['colour.png', 'outline-name']`.
   There is no other list - the route, the attachment accessors and `<File_Type_Icon>` all read it.
4. Update this README with any new source and license information.

The framework test `File_Outline_Icon_Test` (group `attachments`) checks that every mapped
extension resolves to a file here and that every file is a currentColor stroke with no fill.
