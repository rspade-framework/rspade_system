<?php

namespace App\RSpade\Core\Files;

use RuntimeException;

/**
 * Thrown by Svg_Upload_Sanitizer when an uploaded SVG cannot be parsed, so it cannot be
 * sanitized and is not stored. Raised BEFORE any row or blob exists, so there is nothing
 * to clean up; the upload endpoints answer it as a 422 (code unparseable_svg).
 */
class Unparseable_Svg_Exception extends RuntimeException
{
}
