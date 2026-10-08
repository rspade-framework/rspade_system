# Concern: docker

Covers the container image sources under `system/app/RSpade/resource/docker/` - the parts
of them that can be exercised without building an image.

## Domain overview

The images are built from that directory (`build.sh`) and their behaviour at start is the
entrypoint's. Most of it needs a running container to observe. What is tested here is the
logic the entrypoint delegates to standalone scripts, driven directly:

- `php-fpm-workers.sh` (`rspade-php-fpm-workers` in the image) - the production container
  sizes its two php-fpm pools to the machine before php-fpm starts: a total worker count
  from cores, memory and swap (or `PHP_FPM_WORKER_COUNT`), split one share web to three
  Ajax, with a standby per pool. `plan` prints the decision for a machine it is told about;
  `apply` writes pool files, and is pointed at copies.

## Not covered

That the image carries the step and that php-fpm accepts the files it writes need a
built image; both were checked by hand when the step was added. Reading a container's
own CPU and memory limit needs a docker that can apply one, which the docker inside the
development container cannot. The catalog lists each as manual.
