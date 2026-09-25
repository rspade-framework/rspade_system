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

Icons are automatically selected by the `File_Attachment_Model::get_icon_resource()` method based on file extension. The method returns the relative path to the appropriate icon file.

## Icon Format

Every icon the framework maps and rasterises is a **PNG**, because ImageMagick is configured
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

## Adding New Icons

To add support for a new file type:

1. Add the icon to this directory as a PNG (for vector artwork, the SVG plus its 512px raster)
2. Map the file extension to the PNG filename in `File_Attachment_Icons::get_icon_resource_by_file_extension()`
3. Update this README with the icon source and license information
