# Concern: docker

Covers the container image sources under `system/app/RSpade/resource/docker/` - the parts
of them that can be exercised without building an image.

## Domain overview

The images are built from that directory (`build.sh`) and their behaviour at start is the
entrypoint's. Most of it needs a running container to observe. What is tested here is the
logic the entrypoint delegates to standalone scripts, driven directly:

- `sizing.sh` (`rspade-sizing` in the image) - the container sizes itself to the machine
  before any service starts: the Redis cap, the reservation for everything that is not
  PHP, the task workers, the php-fpm total (the lowest of three ceilings, floor 2) for its
  one pool, the memory_limit php-fpm and the CLI share, the development MySQL buffer pool, and
  the record `/var/lib/rspade/sizing.json`. `plan` prints the budget for a machine it is
  told about; `apply --root=` writes under a scratch root holding copies of the pool files.
  The framework's readers - the automatic on-demand task pool and the `rsx:health`
  "Resource Sizing" row - are driven against a scratch record (`Container_Sizing_Test`).
- `security-update.sh` / `security-updates-run.sh` (`rspade-security-update`,
  `rspade-security-updates-run`) - the automatic security updates: the runner's schedule
  through its `plan` mode, the updater along the paths that end before apt is called
  (switched off, an unknown mode), and the scheduled php-fpm restart under a Proxority hold
  with every outside command stubbed on PATH, writing into a scratch status directory. The
  `rsx:health` "Security Updates" row is driven against a scratch status file
  (`Security_Update_Health_Test`).
- `log-to-docker.sh` (`rspade-log-to-docker`) - the echo of the logs onto `docker logs`: its
  Laravel filter through `filter-laravel` (`t3_log_to_docker.sh`).
- `nginx/site.conf` with `nginx/php-urls-checkout.conf` / `php-urls-no-checkout.conf` - the
  `.php` URL policy the entrypoint activates by whether a checkout is mounted, and the image's
  own copy of Proxority (`proxority/`, installed at `/opt/rspade/proxority`): every `.php`
  location includes the policy, both variants pass `nginx -t` on a scratch tree, and the
  Dockerfile, supervisor program and entrypoint wire them (`Nginx_Php_Url_Policy_Test`).
- `entrypoint.sh`'s build-and-migrate decision (`RSPADE_BUILD_MIGRATE_ON_START`): its
  `switch_is_on()` and `start_steps()` extracted verbatim and driven with every spelling,
  mode and target, plus the start order and the serving programs' `autostart=false` checked
  in the source (`t4_build_migrate_on_start.sh`).
- `image_contract.conf` with `bin/lib/rsx_image_contract.sh`, `bootstrap/rsx_image_contract.php`
  and `Rsx_Image_Contract` - the minimum image version: every reader driven against scratch
  requirement, record and marker files, the pre-boot gate in a child process, its exemption
  list, and the Dockerfile's record layer (`Image_Contract_Test`).
- `startup-state.sh` / `wait-started.sh` (`rspade-startup`, `rspade-wait-started`) - the
  record the entrypoint keeps of its start and the waiter on it, driven with `--state-file`
  and a scratch `--proc-root` standing in for PID 1; plus the pre-boot refusal of `rsx:build`
  and `rsx:clean` while the start runs (`bootstrap/rsx_container_starting.php`), as a pure
  function over a scratch /proc tree (`Startup_State_Test`).

## Not covered

What a built container does with the policy - a bare docroot's `.php` files served through
Proxority with no checkout - was checked by hand in the development image (DOCKER-PROX-04/05).

That the image carries the step and that php-fpm accepts the files it writes need a
built image; both were checked by hand when the step was added. Reading a container's
own CPU and memory limit needs a docker that can apply one, which the docker inside the
development container cannot. The catalog lists each as manual. The apt path of the security
updates - the allow-list, the install, the held php-fpm restart against real services - is
checked in a built image by hand, likewise listed as manual, as is the log echo following
the live files, a start with RSPADE_BUILD_MIGRATE_ON_START on, and an image older than the
minimum refusing the start, a command, a request and a pull.
