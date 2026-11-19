# rspade_system

This is a **utility repository** used by the RSpade framework's own tooling. It is not
the repository you clone to build an application.

RSpade uses this repo for two things:

- **Creating new RSpade projects** - the starter template vendors this tree into a new
  project's `system/` directory.
- **Updating existing projects** - `php artisan rsx:framework:pull` fetches framework
  releases from here and applies them to a project's vendored `system/` tree.

Each commit on `master` is one framework release, published as a unit by the RSpade
release process; a project's `system/` submodule points at exactly one of them.

## Looking for RSpade?

To start building with RSpade, visit the actual project repository:

**https://github.com/rspade-framework/rspade**

## Documentation

The framework's guides live in this repository and ship with every release, so a
project's `system/` always carries the version matching the code it runs:

| | |
|---|---|
| [**Developer crash course**](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/developer/DEVELOPER_QUICKSTART.md) | the framework in one sitting — **start here** |
| [Development environment](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/developer/DEVELOPMENT_ENVIRONMENT.md) | Compose or `docker run`, file ownership, the database and GUI clients, a reverse proxy, Claude Code, updating the image |
| [System administration](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/operations/SYSTEM_ADMINISTRATION.md) | what runs in the container, backups, updates, health, logs, maintenance mode, the `/_sys` panel |
| [Production deployment](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/docs/operations/PRODUCTION_DEPLOYMENT.md) | the production image, first install, the deploy procedure, rollback, the container environment |
| [The container image](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/resource/docker/README.md) | the image definition: services, ports, every environment switch, the start sequence |
| `php artisan rsx:man` | the reference, inside a project: every topic, listed (the pages are in [`app/RSpade/man/`](https://github.com/rspade-framework/rspade_system/tree/master/app/RSpade/man)) |
