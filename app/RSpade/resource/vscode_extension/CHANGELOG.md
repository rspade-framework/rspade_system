# Changelog

All notable changes to the **RSpade Framework Support** extension are documented in
this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

Patch versions are assigned automatically by the build, so entries below are grouped
by the date the work landed rather than by individual build number.

## [0.1.226] - 2026-08-25

### Added
- The extension tracks whether the RSpade server is reachable, and pauses only the
  features that need it - formatting, go-to-definition, git decoration and
  refactoring. Highlighting, completion, convention diagnostics and folder colours
  continue to work while the server is down.
- A status bar item appears when the bridge is unreachable, and reconnects on click.
  The new **RSpade: Reconnect IDE Bridge** command does the same from the palette.
- Authentication grants are refreshed every 15 minutes, in step with the server's
  own rotation.

### Changed
- The server address is taken from the grant the server itself writes, so a project
  edited over a remote mount reaches the right host. Previously it was derived
  locally and could resolve to the workstation instead.

## [0.1.224] - 2026-08-20

### Added
- Vendored the jqhtml editor extension alongside this one, so both install from a
  single checkout without a package-manager step.

### Changed
- Released under the MIT licence.
- `install.sh` now installs both extensions, reports a clear diagnosis when the
  `code` CLI cannot be found, and treats a missing npm as a skip rather than a
  failure.
- File-move namespace updates invoke external tooling through argument vectors
  instead of composed shell strings.

## 2026-08-04

### Changed
- Storage paths follow the relocated project storage directory.

## 2026-07-29

### Changed
- Formatting requests authenticate against the IDE bridge using its local-file
  grant, replacing the previous transport.

## 2025-12-24

### Added
- Completion and navigation support for the `Rsx_Time` and `Rsx_Date` APIs.

## 2025-12-10

### Added
- Model constants exported to JavaScript are recognised for navigation.

## 2025-11-25

### Changed
- Blade template highlighting is now provided by the dedicated jqhtml extension,
  leaving this extension focused on PHP and RSX framework support.
- Updated jqhtml slot syntax support from `<#name>` to `<Slot:name>`.

## 2025-11-21

### Added
- SPA sublayout awareness for nested persistent layouts.

### Changed
- Component navigation follows the `Jqhtml_Component` to `Component` rename.

## 2025-11-13

### Added
- Filename convention validation, aligned with the framework's naming rules.
- Route query-parameter awareness in navigation and refactoring.

### Changed
- Automatic file renaming no longer competes with VS Code's own rename handling.

## 2025-10-30

### Added
- Go to Definition for `config()` keys.
- Semantic highlighting for the `that` convention variable and for file references
  written in comments.
- Syntax highlighting for `Rsx::Route()` and `Rsx.Route()` calls.

### Changed
- Global rename commands understand controller-aware `Rsx::Route()` references and
  update them alongside the symbol.

## 2025-10-23

### Added
- Navigation support for the RSX Service and Task system.
- Commands for global method rename, global class rename, and class method sorting.

## 2025-10-18

### Added
- Initial release: PHP namespace maintenance on file move or rename, integrated PHP
  formatting, and optional convention-based automatic file renaming.
