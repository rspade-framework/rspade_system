# Concern: docker

Covers the container image sources under `system/app/RSpade/resource/docker/` - the parts
of them that can be exercised without building an image.

## Domain overview

The images are built from that directory (`build.sh`) and their behaviour at start is the
entrypoint's. Most of it needs a running container to observe. What is tested here is the
logic the entrypoint delegates to standalone scripts, driven directly:

- `sizing.sh` (`rspade-sizing` in the image) - the container sizes itself to the machine
  before any service starts: the Redis cap, the reservation for everything that is not
  PHP, the task workers, the php-fpm total (the lowest of three ceilings, floor 2) and its
  split, the memory_limit php-fpm and the CLI share, the development MySQL buffer pool, and
  the record `/var/lib/rspade/sizing.json`. `plan` prints the budget for a machine it is
  told about; `apply --root=` writes under a scratch root holding copies of the pool files.
  The framework's readers - the automatic on-demand task pool and the `rsx:health`
  "Resource Sizing" row - are driven against a scratch record (`Container_Sizing_Test`).
- `security-update.sh` / `security-updates-run.sh` (`rspade-security-update`,
  `rspade-security-updates-run`) - the automatic security updates: the runner's schedule
  through its `plan` mode, and the updater along the paths that end before apt is called
  (switched off, an unknown mode), writing into a scratch status directory. The
  `rsx:health` "Security Updates" row is driven against a scratch status file
  (`Security_Update_Health_Test`).

## Not covered

That the image carries the step and that php-fpm accepts the files it writes need a
built image; both were checked by hand when the step was added. Reading a container's
own CPU and memory limit needs a docker that can apply one, which the docker inside the
development container cannot. The catalog lists each as manual. The apt path of the security
updates - the allow-list, the install, the php-fpm reload - is checked in a built image by
hand, likewise listed as manual.
