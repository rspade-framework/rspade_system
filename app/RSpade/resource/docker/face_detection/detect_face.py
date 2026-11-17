#!/usr/bin/env python3
"""RSpade face detector: where is the face in this image?

Finds the most prominent face in one image with OpenCV's YuNet detector
(cv2.FaceDetectorYN) and prints its centre, so a cropped thumbnail can be cut
around the face instead of around the middle of the picture.

Usage: <venv>/bin/python detect_face.py <model_path> <image_path>

The model path is an argument, not a constant here, because the application's
configuration owns where the model lives (rsx.thumbnails.face_detection); in the
RSpade image it is /opt/rspade/face_detection/face_detection_yunet_2023mar.onnx.

OUTPUT: exactly one JSON line on stdout.

  exit 0  {"status":"found","x":0.4821,"y":0.3310,"score":0.9412}
          x and y are the face centre as fractions (0-1) of the UPRIGHT image's
          width and height; score is the detector's confidence.
  exit 0  {"status":"none"}                 a readable image with no face in it
  exit 0  {"status":"failed","error":"..."} the image could not be read
  exit 3  {"status":"unavailable","error":"..."}
          OpenCV or the model could not be loaded: the DETECTOR is broken, which
          says nothing about the image, so the caller must not record an answer.

Any other exit (an uncaught exception, traceback on stderr, nothing on stdout) is
a malfunction of this script and is reported as one, never read as an answer.

ORIENTATION: cv2.imread with IMREAD_COLOR applies the EXIF orientation tag, so
the detector sees the photo upright - the same image the thumbnailer draws after
its own auto-orientation. Scaling preserves proportions, so a fraction of the
scaled image is the same fraction of the upright original.
"""

import json
import os
import sys

# Must be set before cv2 is imported: OpenCV reads it once at load. Without it a
# missing or undecodable file prints warnings on stderr alongside our answer.
os.environ['OPENCV_LOG_LEVEL'] = 'ERROR'

EXIT_UNAVAILABLE = 3

# Detection runs on an image no larger than this on its longest side: a phone
# photo at full resolution is slow to scan and finds no face a 1280 px copy misses.
MAX_SIDE = 1280

# Faces scoring below this are ignored. 0.8 keeps out patterned fabric, posters
# and similar near-misses while finding real faces at profile-photo sizes.
SCORE_THRESHOLD = 0.8


def answer(payload, exit_code=0):
    print(json.dumps(payload, separators=(',', ':')))
    sys.exit(exit_code)


def main(argv):
    if len(argv) != 3:
        sys.stderr.write('Usage: detect_face.py <model_path> <image_path>\n')
        sys.exit(2)
    model_path, image_path = argv[1], argv[2]

    try:
        import cv2
    except Exception as e:
        answer({'status': 'unavailable', 'error': 'cannot import cv2: %s' % e}, EXIT_UNAVAILABLE)

    # The model is loaded BEFORE the image is read, so a broken detector is always
    # reported as unavailable - never as a "failed" verdict on an image it did not
    # get as far as looking at. The input size is set per image below.
    if not os.path.isfile(model_path):
        answer({'status': 'unavailable', 'error': 'model not found: %s' % model_path}, EXIT_UNAVAILABLE)
    try:
        detector = cv2.FaceDetectorYN.create(model_path, '', (320, 320), SCORE_THRESHOLD)
    except Exception as e:
        answer({'status': 'unavailable', 'error': 'cannot load model: %s' % str(e).strip()}, EXIT_UNAVAILABLE)

    image = cv2.imread(image_path, cv2.IMREAD_COLOR)
    if image is None:
        answer({'status': 'failed', 'error': 'unreadable image'})

    height, width = image.shape[:2]
    scale = min(1.0, MAX_SIDE / float(max(width, height)))
    if scale < 1.0:
        width = max(1, int(round(width * scale)))
        height = max(1, int(round(height * scale)))
        image = cv2.resize(image, (width, height), interpolation=cv2.INTER_AREA)

    detector.setInputSize((width, height))
    _, faces = detector.detect(image)
    if faces is None or len(faces) == 0:
        answer({'status': 'none'})

    # Each row is x, y, w, h, five landmark pairs, then the score. The largest face
    # is the subject; smaller ones are people in the background.
    face = max(faces, key=lambda f: float(f[2]) * float(f[3]))
    x, y, w, h = (float(v) for v in face[:4])
    # A box may overhang the edge of the image, so the centre is clamped into it.
    centre_x = min(1.0, max(0.0, (x + w / 2.0) / width))
    centre_y = min(1.0, max(0.0, (y + h / 2.0) / height))
    answer({
        'status': 'found',
        'x': round(centre_x, 4),
        'y': round(centre_y, 4),
        'score': round(float(face[14]), 4),
    })


if __name__ == '__main__':
    main(sys.argv)
