#!/usr/bin/env python3
"""A stand-in for the face detector, for the face_thumbnails tests.

Invoked exactly as Face_Detection invokes the real detector - argv [python, script, model,
image] - with the MODEL argument naming a JSON spec file the test wrote:

  {"exit": 0, "stdout": "<the line to print>", "stderr": "<text for stderr>", "log": "<path>"}

Every call appends the image path to the "log" file, so a test can prove the detector was (or
was not) run; then the stub prints stdout and stderr verbatim and exits with "exit".
"""

import json
import sys


def main(argv):
    spec_path, image_path = argv[1], argv[2]
    with open(spec_path) as handle:
        spec = json.load(handle)

    if spec.get('log'):
        with open(spec['log'], 'a') as log:
            log.write(image_path + '\n')

    if spec.get('stderr'):
        sys.stderr.write(spec['stderr'])
    if spec.get('stdout') is not None:
        sys.stdout.write(spec['stdout'])
    sys.exit(int(spec.get('exit', 0)))


if __name__ == '__main__':
    main(sys.argv)
