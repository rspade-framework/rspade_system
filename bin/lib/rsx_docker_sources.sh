# The RSpade container images' sources, hashed - for bash, sourced, never executed.
#
# One answer to "have the Docker sources changed?", shared by the two framework-author
# tools that ask it: bin/publish_docker_images records the hash of the sources each image
# version was built from (docs.dev/docker_images/publish_state.conf, sources_hash=), and
# bin/publish warns when the sources it is about to release differ from the ones the newest
# published images were built from.
#
# Usage:
#   . "$SYSTEM_DIR/bin/lib/rsx_docker_sources.sh"
#   hash="$(rsx_docker_sources_hash "$SYSTEM_DIR/app/RSpade/resource/docker")"

# rsx_docker_sources_hash <docker dir> : 16 hex characters over every file in the build
# context, by path and content.
rsx_docker_sources_hash() {
    (cd "$1" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum | sha256sum | cut -c1-16)
}
