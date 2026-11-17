<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use Closure;
use RuntimeException;

/**
 * Exif_Gps - the GPS position in an image's EXIF: read it, or hand the image out without it.
 *
 * A STORED ORIGINAL IS NEVER CHANGED. Its bytes, hash and deduplication stay exactly as
 * uploaded, so server-side code can still read where a photo was taken (read_position()).
 * What changes is what a CLIENT is handed: every route that serves an original's bytes
 * (/_download, /_inline, the members of /_download_zip, File_Storage_Model::download_response()
 * / inline_response()) serves an image that carries a GPS position with that position removed
 * (rsx.files.strip_gps_when_served, on by default). An image with no GPS, and every other
 * file, is served byte for byte as stored.
 *
 * HOW, WITHOUT RE-ENCODING. EXIF is a small TIFF structure. Its first directory (IFD0) holds a
 * GPSInfo entry (tag 0x8825) pointing at a directory of GPS fields. Removing the position means
 * removing that entry from IFD0 - the entries after it move up one slot, the freed slot at the
 * end becomes zeros, the count drops by one - and overwriting the GPS directory and every
 * value it points at with zeros. The TIFF keeps its length, so every offset in the file stays
 * valid, and the edit is a handful of byte ranges at fixed offsets: patches(). A served file is
 * the stored file streamed with those ranges overlaid (apply_patches()), so it is never
 * written anywhere and nothing is decoded. The orientation tag, the camera fields, the ICC
 * profile and every pixel are untouched.
 *
 * CONTAINERS (detected by their magic bytes, never by name), each read through a few seeks:
 *   JPEG                 every APP1 "Exif" segment before the scan data
 *   PNG                  the eXIf chunk, its CRC patched to match
 *   WebP                 the EXIF chunk
 *   HEIC / HEIF / AVIF   the "Exif" item, through the meta box's iinf / iloc (one extent, in
 *                        the file or in idat)
 *   TIFF (and TIFF-based camera RAW)   the file's own IFD0
 * NOT REMOVED: a position written into XMP (an editor's exif:GPS* attributes in a text
 * packet) or a maker note, and the EXIF of a secondary image appended after a JPEG's primary
 * one (MPF depth maps, previews).
 *
 * A malformed EXIF structure (an offset outside the file, a directory that does not fit) is
 * a RuntimeException: it cannot be edited safely, and the caller decides (the serving routes
 * serve the file as stored and log a warning naming it -
 * File_Storage_Model::gps_patches_for_serving()).
 */
class Exif_Gps
{
    /** IFD0 tag of the pointer to the GPS directory. */
    public const TAG_GPS_IFD = 0x8825;

    /** Bytes per value of each TIFF field type. */
    private const TYPE_SIZES = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8, 13 => 4];

    // ============================================================================================
    // PUBLIC SURFACE
    // ============================================================================================

    /**
     * The byte ranges that remove the GPS position from the file at $path.
     *
     * @param string $path
     * @return array<int, string> offset => replacement bytes (same length as what they cover);
     *                            empty when the file carries no GPS position, or is not an image
     *                            format this reads.
     * @throws RuntimeException when the EXIF structure is malformed
     */
    public static function patches_for_file(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("cannot open {$path}");
        }

        try {
            $size = (int) fstat($handle)['size'];
            $read = static function (int $offset, int $length) use ($handle, $size): string {
                static::__within($offset, $length, $size);
                if ($length === 0) {
                    return '';
                }
                fseek($handle, $offset);
                $bytes = (string) fread($handle, $length);
                if (strlen($bytes) !== $length) {
                    throw new RuntimeException("short read at byte {$offset}");
                }

                return $bytes;
            };

            return static::__patches($read, $size);
        } finally {
            fclose($handle);
        }
    }

    /**
     * patches_for_file() for bytes held in memory.
     *
     * @param string $bytes
     * @return array<int, string>
     * @throws RuntimeException when the EXIF structure is malformed
     */
    public static function patches(string $bytes): array
    {
        $size = strlen($bytes);
        $read = static function (int $offset, int $length) use ($bytes, $size): string {
            static::__within($offset, $length, $size);

            return substr($bytes, $offset, $length);
        };

        return static::__patches($read, $size);
    }

    /**
     * $bytes without their GPS position, or null when they carry none.
     *
     * @param string $bytes
     * @return string|null Same length as $bytes.
     * @throws RuntimeException when the EXIF structure is malformed
     */
    public static function strip_bytes(string $bytes): ?string
    {
        $patches = static::patches($bytes);

        return $patches === [] ? null : static::apply_patches($bytes, 0, $patches);
    }

    /**
     * Overlay patches on one chunk of a file being streamed.
     *
     * @param string $chunk Bytes of the file starting at $chunk_offset.
     * @param int $chunk_offset
     * @param array<int, string> $patches From patches_for_file().
     * @return string The chunk with every patch byte that falls inside it applied.
     */
    public static function apply_patches(string $chunk, int $chunk_offset, array $patches): string
    {
        $chunk_end = $chunk_offset + strlen($chunk);

        foreach ($patches as $offset => $replacement) {
            $end = $offset + strlen($replacement);
            if ($end <= $chunk_offset || $offset >= $chunk_end) {
                continue;
            }

            $from = max($offset, $chunk_offset);
            $to = min($end, $chunk_end);
            $chunk = substr_replace($chunk, substr($replacement, $from - $offset, $to - $from), $from - $chunk_offset, $to - $from);
        }

        return $chunk;
    }

    /**
     * Where the image at $path was taken, from its EXIF GPS fields: the server-side read that
     * keeps geocoding possible although clients are never handed the position.
     *
     * @param string $path
     * @return array{latitude: float, longitude: float}|null Decimal degrees (south and west
     *                                                       negative); null when the file has no
     *                                                       complete position or is not an image
     *                                                       format this reads.
     * @throws RuntimeException when the EXIF structure is malformed
     */
    public static function read_position(string $path): ?array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("cannot open {$path}");
        }

        try {
            $size = (int) fstat($handle)['size'];
            $read = static function (int $offset, int $length) use ($handle, $size): string {
                static::__within($offset, $length, $size);
                if ($length === 0) {
                    return '';
                }
                fseek($handle, $offset);

                return (string) fread($handle, $length);
            };

            foreach (static::__tiffs($read, $size) as [$base, $length]) {
                $position = static::__tiff_position($read, $base, $length);
                if ($position !== null) {
                    return $position;
                }
            }

            return null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * The patches that remove the GPS directory from one TIFF structure held in memory.
     * Public for the tests.
     *
     * @param string $tiff
     * @return string|null The TIFF without GPS (same length), or null when it had none.
     * @throws RuntimeException when the structure is malformed
     */
    public static function strip_tiff(string $tiff): ?string
    {
        $size = strlen($tiff);
        $read = static function (int $offset, int $length) use ($tiff, $size): string {
            static::__within($offset, $length, $size);

            return substr($tiff, $offset, $length);
        };

        $patches = static::__tiff_patches($read, 0, $size);

        return $patches === [] ? null : static::apply_patches($tiff, 0, $patches);
    }

    // ============================================================================================
    // CONTAINERS
    // ============================================================================================

    /** Every patch for every EXIF TIFF in the file. */
    private static function __patches(Closure $read, int $size): array
    {
        if (static::__container($read, $size) === 'png') {
            return static::__png_patches($read, $size);
        }

        $patches = [];
        foreach (static::__tiffs($read, $size) as [$base, $length]) {
            $patches += static::__tiff_patches($read, $base, $length);
        }

        return $patches;
    }

    /** 'jpeg' | 'png' | 'webp' | 'isobmff' | 'tiff' | null, from the file's first bytes. */
    private static function __container(Closure $read, int $size): ?string
    {
        $head = $read(0, min(16, $size));

        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'jpeg';
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return 'png';
        }
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (str_starts_with($head, "II\x2a\x00") || str_starts_with($head, "MM\x00\x2a")) {
            return 'tiff';
        }
        if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp'
            && in_array(substr($head, 8, 4), ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'mif1', 'msf1', 'avif', 'avis'], true)) {
            return 'isobmff';
        }

        return null;
    }

    /**
     * Where each EXIF TIFF structure lies in the file.
     *
     * @return array<int, array{0: int, 1: int}> [base offset, length] per TIFF
     */
    private static function __tiffs(Closure $read, int $size): array
    {
        return match (static::__container($read, $size)) {
            'jpeg' => static::__jpeg_tiffs($read, $size),
            'png' => static::__png_tiff($read, $size) === null ? [] : [static::__png_tiff($read, $size)['tiff']],
            'webp' => static::__webp_tiffs($read, $size),
            'isobmff' => static::__isobmff_tiffs($read, $size),
            'tiff' => [[0, $size]],
            default => [],
        };
    }

    /** Every APP1 Exif segment before the scan data. */
    private static function __jpeg_tiffs(Closure $read, int $size): array
    {
        $tiffs = [];
        $pos = 2;

        while ($pos + 4 <= $size) {
            $header = $read($pos, 4);
            if ($header[0] !== "\xFF") {
                throw new RuntimeException("JPEG marker expected at byte {$pos}");
            }
            $marker = ord($header[1]);
            if ($marker === 0xFF) {
                $pos++;                                     // fill byte
                continue;
            }
            if ($marker === 0xD9 || $marker === 0xDA) {
                break;                                      // end of image / start of scan
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $pos += 2;                                  // no length field
                continue;
            }

            $segment_length = unpack('n', substr($header, 2, 2))[1];
            if ($segment_length < 2 || $pos + 2 + $segment_length > $size) {
                throw new RuntimeException("JPEG segment at byte {$pos} runs past the end of the file");
            }

            if ($marker === 0xE1 && $segment_length >= 8 && $read($pos + 4, 6) === "Exif\x00\x00") {
                $tiffs[] = [$pos + 10, $segment_length - 8];
            }

            $pos += 2 + $segment_length;
        }

        return $tiffs;
    }

    /**
     * The PNG eXIf chunk, if any.
     *
     * @return array{tiff: array{0: int, 1: int}, chunk: int}|null The TIFF's [base, length] and
     *                                                             the chunk's start.
     */
    private static function __png_tiff(Closure $read, int $size): ?array
    {
        $pos = 8;

        while ($pos + 12 <= $size) {
            $header = $read($pos, 8);
            $chunk_length = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            if ($pos + 12 + $chunk_length > $size) {
                throw new RuntimeException("PNG chunk {$type} runs past the end of the file");
            }

            if ($type === 'eXIf') {
                return ['tiff' => [$pos + 8, $chunk_length], 'chunk' => $pos];
            }
            if ($type === 'IEND') {
                break;
            }

            $pos += 12 + $chunk_length;
        }

        return null;
    }

    /** The eXIf chunk's TIFF patches plus its CRC, recomputed over the patched content. */
    private static function __png_patches(Closure $read, int $size): array
    {
        $exif = static::__png_tiff($read, $size);
        if ($exif === null) {
            return [];
        }

        [$base, $length] = $exif['tiff'];
        $patches = static::__tiff_patches($read, $base, $length);
        if ($patches === []) {
            return [];
        }

        $patched = static::apply_patches($read($base, $length), $base, $patches);
        $patches[$base + $length] = pack('N', crc32('eXIf' . $patched));

        return $patches;
    }

    /** The WebP EXIF chunk (with or without an "Exif\0\0" prefix before its TIFF). */
    private static function __webp_tiffs(Closure $read, int $size): array
    {
        $pos = 12;

        while ($pos + 8 <= $size) {
            $header = $read($pos, 8);
            $type = substr($header, 0, 4);
            $chunk_length = unpack('V', substr($header, 4, 4))[1];
            if ($pos + 8 + $chunk_length > $size) {
                throw new RuntimeException("WebP chunk {$type} runs past the end of the file");
            }

            if ($type === 'EXIF') {
                $base = $pos + 8;
                $length = $chunk_length;
                if ($length >= 6 && $read($base, 6) === "Exif\x00\x00") {
                    $base += 6;
                    $length -= 6;
                }

                return [[$base, $length]];
            }

            $pos += 8 + $chunk_length + ($chunk_length % 2);
        }

        return [];
    }

    /**
     * The "Exif" item of a HEIF-family file: its id from iinf, its bytes through iloc (a file
     * offset, or an offset into idat), past the 4-byte header offset.
     */
    private static function __isobmff_tiffs(Closure $read, int $size): array
    {
        $meta = static::__find_box($read, 0, $size, 'meta');
        if ($meta === null) {
            return [];
        }
        // meta is a full box: four bytes of version and flags before its children.
        $children_start = $meta['data'] + 4;
        $children_end = $meta['end'];

        $iinf = static::__find_box($read, $children_start, $children_end, 'iinf');
        $iloc = static::__find_box($read, $children_start, $children_end, 'iloc');
        if ($iinf === null || $iloc === null) {
            return [];
        }

        $exif_id = static::__exif_item_id($read, $iinf);
        if ($exif_id === null) {
            return [];
        }

        $extent = static::__item_extent($read, $iloc, $exif_id);
        if ($extent === null) {
            throw new RuntimeException("the Exif item {$exif_id} has no single locatable extent");
        }
        [$construction_method, $offset, $item_length] = $extent;

        if ($construction_method === 1) {
            $idat = static::__find_box($read, $children_start, $children_end, 'idat');
            if ($idat === null) {
                throw new RuntimeException('the Exif item lives in idat, which is missing');
            }
            $offset += $idat['data'];
        } elseif ($construction_method !== 0) {
            throw new RuntimeException("the Exif item uses iloc construction method {$construction_method}");
        }

        static::__within($offset, $item_length, $size);
        if ($item_length < 4) {
            throw new RuntimeException('the Exif item is too short');
        }

        $header_offset = unpack('N', $read($offset, 4))[1];
        $tiff_length = $item_length - 4 - $header_offset;
        if ($tiff_length <= 0) {
            throw new RuntimeException('the Exif item header offset points past the item');
        }

        return [[$offset + 4 + $header_offset, $tiff_length]];
    }

    /**
     * The first box of $type among the boxes laid end to end in [$start, $end).
     *
     * @return array{data: int, end: int}|null The box's payload start and end.
     */
    private static function __find_box(Closure $read, int $start, int $end, string $type): ?array
    {
        $pos = $start;
        while ($pos + 8 <= $end) {
            $header = $read($pos, 8);
            $size = unpack('N', substr($header, 0, 4))[1];
            $box_type = substr($header, 4, 4);
            $header_size = 8;
            if ($size === 1) {
                $large = unpack('N2', $read($pos + 8, 8));
                $size = $large[1] * 4294967296 + $large[2];
                $header_size = 16;
            } elseif ($size === 0) {
                $size = $end - $pos;
            }
            if ($size < $header_size || $pos + $size > $end) {
                throw new RuntimeException("box {$box_type} at byte {$pos} runs past its parent");
            }

            if ($box_type === $type) {
                return ['data' => $pos + $header_size, 'end' => $pos + $size];
            }

            $pos += $size;
        }

        return null;
    }

    /** The item id of the item whose type is 'Exif', from iinf's infe entries (versions 2-3). */
    private static function __exif_item_id(Closure $read, array $iinf): ?int
    {
        $box = $read($iinf['data'], $iinf['end'] - $iinf['data']);
        $version = ord($box[0]);
        $pos = 4 + ($version === 0 ? 2 : 4);
        $end = strlen($box);

        while ($pos + 8 <= $end) {
            $size = unpack('N', substr($box, $pos, 4))[1];
            if ($size < 8 || $pos + $size > $end) {
                throw new RuntimeException('an infe box runs past iinf');
            }
            if (substr($box, $pos + 4, 4) === 'infe' && $size >= 20) {
                $infe_version = ord($box[$pos + 8]);
                if ($infe_version >= 2) {
                    $id_size = $infe_version === 2 ? 2 : 4;
                    $item_id = unpack($id_size === 2 ? 'n' : 'N', substr($box, $pos + 12, $id_size))[1];
                    if (substr($box, $pos + 12 + $id_size + 2, 4) === 'Exif') {
                        return $item_id;
                    }
                }
            }
            $pos += $size;
        }

        return null;
    }

    /**
     * Where an item's bytes are, from iloc.
     *
     * @return array{0: int, 1: int, 2: int}|null [construction method, offset, length] of its
     *                                            one extent; null when it has not exactly one.
     */
    private static function __item_extent(Closure $read, array $iloc, int $wanted_id): ?array
    {
        $box = $read($iloc['data'], $iloc['end'] - $iloc['data']);
        $end = strlen($box);
        $uint = static function (int $at, int $width) use ($box, $end): int {
            static::__within($at, $width, $end);

            return match ($width) {
                0 => 0,
                2 => unpack('n', substr($box, $at, 2))[1],
                4 => unpack('N', substr($box, $at, 4))[1],
                8 => unpack('N', substr($box, $at, 4))[1] * 4294967296 + unpack('N', substr($box, $at + 4, 4))[1],
                default => throw new RuntimeException("iloc field width {$width} is not 0, 2, 4 or 8"),
            };
        };

        $version = ord($box[0]);
        $offset_size = ord($box[4]) >> 4;
        $length_size = ord($box[4]) & 0x0F;
        $base_offset_size = ord($box[5]) >> 4;
        $index_size = ($version === 1 || $version === 2) ? (ord($box[5]) & 0x0F) : 0;
        $pos = 6;

        $item_count = $uint($pos, $version < 2 ? 2 : 4);
        $pos += $version < 2 ? 2 : 4;

        for ($i = 0; $i < $item_count; $i++) {
            $item_id = $uint($pos, $version < 2 ? 2 : 4);
            $pos += $version < 2 ? 2 : 4;

            $construction_method = 0;
            if ($version === 1 || $version === 2) {
                $construction_method = $uint($pos, 2) & 0x0F;
                $pos += 2;
            }
            $pos += 2;                                           // data_reference_index
            $base_offset = $uint($pos, $base_offset_size);
            $pos += $base_offset_size;
            $extent_count = $uint($pos, 2);
            $pos += 2;

            $extents = [];
            for ($e = 0; $e < $extent_count; $e++) {
                $pos += $index_size;
                $extent_offset = $uint($pos, $offset_size);
                $pos += $offset_size;
                $extent_length = $uint($pos, $length_size);
                $pos += $length_size;
                $extents[] = [$base_offset + $extent_offset, $extent_length];
            }

            if ($item_id === $wanted_id) {
                if (count($extents) !== 1 || $extents[0][1] === 0) {
                    return null;
                }

                return [$construction_method, $extents[0][0], $extents[0][1]];
            }
        }

        return null;
    }

    // ============================================================================================
    // THE TIFF
    // ============================================================================================

    /**
     * Open a TIFF at [$base, $base + $length): its byte order, IFD0 and the GPSInfo entry.
     *
     * @return array{big: bool, ifd0: int, count: int, gps_index: ?int, gps_ifd: ?int} offsets
     *         relative to $base
     */
    private static function __open_tiff(Closure $read, int $base, int $length): array
    {
        if ($length < 8) {
            throw new RuntimeException('the TIFF header is truncated');
        }

        $header = $read($base, 8);
        $order = substr($header, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            throw new RuntimeException('the TIFF byte order is not II or MM');
        }
        $big = $order === 'MM';

        if (unpack($big ? 'n' : 'v', substr($header, 2, 2))[1] !== 42) {
            throw new RuntimeException('the TIFF magic number is not 42');
        }

        $ifd0 = unpack($big ? 'N' : 'V', substr($header, 4, 4))[1];
        static::__within($ifd0, 2, $length);
        $count = unpack($big ? 'n' : 'v', $read($base + $ifd0, 2))[1];
        static::__within($ifd0, 2 + $count * 12 + 4, $length);
        $entries = $read($base + $ifd0 + 2, $count * 12);

        $gps_index = null;
        $gps_ifd = null;
        for ($i = 0; $i < $count; $i++) {
            if (unpack($big ? 'n' : 'v', substr($entries, $i * 12, 2))[1] === static::TAG_GPS_IFD) {
                $gps_index = $i;
                $gps_ifd = unpack($big ? 'N' : 'V', substr($entries, $i * 12 + 8, 4))[1];
                break;
            }
        }

        return ['big' => $big, 'ifd0' => $ifd0, 'count' => $count, 'gps_index' => $gps_index, 'gps_ifd' => $gps_ifd];
    }

    /**
     * The GPS directory's entries.
     *
     * @return array<int, array{tag: int, type: int, count: int, value: string, at: int}> value =
     *         the four inline bytes; at = the entry's offset relative to the TIFF
     */
    private static function __gps_entries(Closure $read, int $base, int $length, array $tiff): array
    {
        $big = $tiff['big'];
        $gps_ifd = $tiff['gps_ifd'];

        static::__within($gps_ifd, 2, $length);
        $count = unpack($big ? 'n' : 'v', $read($base + $gps_ifd, 2))[1];
        static::__within($gps_ifd, 2 + $count * 12 + 4, $length);
        $raw = $read($base + $gps_ifd + 2, $count * 12);

        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $entry = substr($raw, $i * 12, 12);
            $entries[] = [
                'tag' => unpack($big ? 'n' : 'v', substr($entry, 0, 2))[1],
                'type' => unpack($big ? 'n' : 'v', substr($entry, 2, 2))[1],
                'count' => unpack($big ? 'N' : 'V', substr($entry, 4, 4))[1],
                'value' => substr($entry, 8, 4),
                'at' => $gps_ifd + 2 + $i * 12,
            ];
        }

        return $entries;
    }

    /** Absolute-offset patches that remove the GPS directory from one TIFF. */
    private static function __tiff_patches(Closure $read, int $base, int $length): array
    {
        $tiff = static::__open_tiff($read, $base, $length);
        if ($tiff['gps_index'] === null) {
            return [];
        }

        $big = $tiff['big'];
        $patches = [];

        // 1. Zero every value the GPS directory points at, and the directory itself.
        $entries = static::__gps_entries($read, $base, $length, $tiff);
        foreach ($entries as $entry) {
            $value_size = isset(static::TYPE_SIZES[$entry['type']]) ? static::TYPE_SIZES[$entry['type']] * $entry['count'] : 0;
            if ($value_size > 4) {
                $value_offset = unpack($big ? 'N' : 'V', $entry['value'])[1];
                static::__within($value_offset, $value_size, $length);
                $patches[$base + $value_offset] = str_repeat("\x00", $value_size);
            }
        }
        $patches[$base + $tiff['gps_ifd']] = str_repeat("\x00", 2 + count($entries) * 12 + 4);

        // 2. Remove the GPSInfo entry from IFD0: the entries after it and the next-IFD pointer
        //    move up one slot, the freed twelve bytes at the end become zeros, the count drops.
        $ifd0 = $tiff['ifd0'];
        $count = $tiff['count'];
        $slot = $ifd0 + 2 + $tiff['gps_index'] * 12;
        $tail = $read($base + $slot + 12, ($count - $tiff['gps_index'] - 1) * 12 + 4);
        $patches[$base + $ifd0] = $big ? pack('n', $count - 1) : pack('v', $count - 1);
        $patches[$base + $slot] = $tail . str_repeat("\x00", 12);

        // The ranges must not overlap: a GPS directory inside IFD0 is not a structure to edit.
        ksort($patches);
        $previous_end = -1;
        foreach ($patches as $offset => $replacement) {
            if ($offset < $previous_end) {
                throw new RuntimeException('the GPS directory overlaps IFD0');
            }
            $previous_end = $offset + strlen($replacement);
        }

        return $patches;
    }

    /** The decimal position in one TIFF's GPS directory, or null when it holds none. */
    private static function __tiff_position(Closure $read, int $base, int $length): ?array
    {
        $tiff = static::__open_tiff($read, $base, $length);
        if ($tiff['gps_index'] === null) {
            return null;
        }

        $big = $tiff['big'];
        $fields = [];
        foreach (static::__gps_entries($read, $base, $length, $tiff) as $entry) {
            $fields[$entry['tag']] = $entry;
        }

        // 1 GPSLatitudeRef, 2 GPSLatitude, 3 GPSLongitudeRef, 4 GPSLongitude.
        if (!isset($fields[1], $fields[2], $fields[3], $fields[4])) {
            return null;
        }

        $degrees = static function (array $entry) use ($read, $base, $length, $big): ?float {
            if ($entry['type'] !== 5 || $entry['count'] < 1 || $entry['count'] > 3) {
                return null;
            }
            $offset = unpack($big ? 'N' : 'V', $entry['value'])[1];
            static::__within($offset, $entry['count'] * 8, $length);
            $values = unpack($big ? 'N*' : 'V*', $read($base + $offset, $entry['count'] * 8));
            $parts = [];
            for ($i = 1; $i <= $entry['count'] * 2; $i += 2) {
                if ($values[$i + 1] === 0) {
                    return null;
                }
                $parts[] = $values[$i] / $values[$i + 1];
            }

            return ($parts[0] ?? 0.0) + ($parts[1] ?? 0.0) / 60 + ($parts[2] ?? 0.0) / 3600;
        };

        $latitude = $degrees($fields[2]);
        $longitude = $degrees($fields[4]);
        if ($latitude === null || $longitude === null) {
            return null;
        }

        if (strtoupper($fields[1]['value'][0]) === 'S') {
            $latitude = -$latitude;
        }
        if (strtoupper($fields[3]['value'][0]) === 'W') {
            $longitude = -$longitude;
        }

        if (abs($latitude) > 90 || abs($longitude) > 180) {
            return null;
        }

        return ['latitude' => $latitude, 'longitude' => $longitude];
    }

    /** Prove [$offset, $offset + $size) lies inside $length bytes. */
    private static function __within(int $offset, int $size, int $length): void
    {
        if ($offset < 0 || $size < 0 || $offset + $size > $length) {
            throw new RuntimeException("an offset ({$offset} + {$size}) points outside the structure ({$length} bytes)");
        }
    }
}
