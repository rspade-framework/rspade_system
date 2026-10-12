<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * Draws cube.gif, the animated image the attachment tests read: a shaded cube turning once
 * about two axes, as a looping GIF.
 *
 * The file beside this script is its output, committed so the tests have a fixed subject
 * (Animated_Image_Test states its frame count). Run it again only to change the picture:
 *
 *     php generate_cube_gif.php            # writes cube.gif beside this script
 *     php generate_cube_gif.php out.gif    # or somewhere else
 *
 * Plain PHP with the imagick extension; nothing of the framework is loaded.
 */

const CUBE_FRAMES = 24;
const CUBE_SIZE = 160;
const CUBE_DELAY = 6;            // hundredths of a second a frame
const CUBE_SCALE = 34.0;
const CUBE_CAMERA = 5.5;         // distance of the eye from the cube's centre, in cube units

$out_path = $argv[1] ?? __DIR__ . '/cube.gif';

// The eight corners, and each face as four corner indexes with its own colour.
$corners = [
    [-1, -1, -1], [1, -1, -1], [1, 1, -1], [-1, 1, -1],
    [-1, -1, 1], [1, -1, 1], [1, 1, 1], [-1, 1, 1],
];
$faces = [
    [[0, 1, 2, 3], [231, 76, 60]],
    [[4, 5, 6, 7], [52, 152, 219]],
    [[0, 1, 5, 4], [46, 204, 113]],
    [[2, 3, 7, 6], [241, 196, 15]],
    [[1, 2, 6, 5], [155, 89, 182]],
    [[0, 3, 7, 4], [230, 126, 34]],
];

$animation = new Imagick();

for ($frame = 0; $frame < CUBE_FRAMES; $frame++) {
    // One whole turn about the vertical axis over the loop, half of one about the horizontal:
    // the last frame leads back into the first.
    $turn = 2 * M_PI * $frame / CUBE_FRAMES;
    $yaw = $turn;
    $pitch = 0.6 + 0.35 * sin($turn);

    $rotated = [];
    $projected = [];
    foreach ($corners as [$x, $y, $z]) {
        $x1 = $x * cos($yaw) + $z * sin($yaw);
        $z1 = -$x * sin($yaw) + $z * cos($yaw);
        $y1 = $y * cos($pitch) - $z1 * sin($pitch);
        $z2 = $y * sin($pitch) + $z1 * cos($pitch);

        $rotated[] = [$x1, $y1, $z2];

        $perspective = CUBE_CAMERA / (CUBE_CAMERA - $z2);
        $projected[] = [
            'x' => CUBE_SIZE / 2 + $x1 * CUBE_SCALE * $perspective,
            'y' => CUBE_SIZE / 2 + $y1 * CUBE_SCALE * $perspective,
        ];
    }

    // Painter's order: the faces furthest from the eye first.
    $ordered = $faces;
    usort($ordered, function ($a, $b) use ($rotated) {
        $depth = fn ($face) => array_sum(array_map(fn ($i) => $rotated[$i][2], $face[0])) / 4;

        return $depth($a) <=> $depth($b);
    });

    $image = new Imagick();
    $image->newImage(CUBE_SIZE, CUBE_SIZE, new ImagickPixel('#101820'));
    $image->setImageFormat('gif');

    $draw = new ImagickDraw();
    $draw->setStrokeColor(new ImagickPixel('#101820'));
    $draw->setStrokeWidth(2);
    $draw->setStrokeAntialias(true);

    foreach ($ordered as [$indexes, [$red, $green, $blue]]) {
        // Shade by how squarely the face looks at the eye, so the turning reads as depth.
        $depth = array_sum(array_map(fn ($i) => $rotated[$i][2], $indexes)) / 4;
        $light = 0.55 + 0.45 * max(0.0, min(1.0, ($depth + 1) / 2));

        $draw->setFillColor(new ImagickPixel(sprintf(
            'rgb(%d,%d,%d)',
            (int) round($red * $light),
            (int) round($green * $light),
            (int) round($blue * $light)
        )));
        $draw->polygon(array_map(fn ($i) => $projected[$i], $indexes));
    }

    $image->drawImage($draw);
    $image->setImageDelay(CUBE_DELAY);
    $image->setImageDispose(Imagick::DISPOSE_BACKGROUND);

    $animation->addImage($image);
}

$animation->setImageIterations(0);
$animation->writeImages($out_path, true);

echo basename($out_path) . ': ' . CUBE_FRAMES . ' frames, ' . CUBE_SIZE . 'x' . CUBE_SIZE . ', ' . filesize($out_path) . " bytes\n";
