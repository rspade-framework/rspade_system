<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Lib\Support\UrlFetch;

/**
 * What Rsx_Url_Fetch::to_temp_file() downloaded: where the bytes are, and what the server
 * said about them.
 *
 * $path is a temp file the caller now owns - move it, read it, or delete() it. Whatever is
 * still there when the process ends is deleted then. $content_type and $filename are the
 * SERVER's claims (the Content-Type header, the Content-Disposition name or the URL's last
 * path segment): $filename is already safe to store or show (Rsx_File_Name::safe()), but
 * neither says what the bytes really are - sniff the file when that matters.
 */
#[Instantiatable]
class Rsx_Url_Fetch_Result
{
    public function __construct(
        public readonly string $path,
        public readonly ?string $content_type,
        public readonly string $filename,
        public readonly string $final_url,
        public readonly int $size,
    ) {
    }

    /** Delete the temp file now; nothing when it was already moved or deleted. */
    public function delete(): void
    {
        if (file_exists($this->path)) {
            unlink($this->path);
        }
    }
}
