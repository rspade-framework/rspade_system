# Scaffold (module / feature generators)

## Domain

The artisan generators that write new application code into `rsx/app/`:

- `rsx:app:module:create <name> [--blade]` - a whole module. Default is an SPA
  module (module bundle + `#[SPA]` bootstrap + persistent `Spa_Layout` pair +
  the index feature); `--blade` scaffolds the server-rendered module ladder
  (bundle + Blade layout + `#[Route]` controller + view/js/scss) for public and
  SEO pages.
- `rsx:app:module:feature:create <module> <feature> [--blade]` - one screen.
  Default writes an Action pair plus an empty, gated Ajax controller in the
  feature's own directory (the index feature included); `--blade` writes the
  legacy controller + view + js + scss set.

Both are template-driven: `Core/CodeTemplates/StubProcessor.php` derives the
replacements and `Core/CodeTemplates/stubs/*.stub` supply the content. The
processor THROWS on an unreplaced `{{ placeholder }}`, so a stub referencing a
key the processor does not derive fails loudly at generation time.

## Source under test

- `app/RSpade/Commands/Rsx/Module_Create_Command.php`
- `app/RSpade/Commands/Rsx/Module_Feature_Create_Command.php`
- `app/RSpade/Core/CodeTemplates/StubProcessor.php`
  (`generate_replacements()` for Blade, `generate_spa_replacements()` for SPA)
- `app/RSpade/Core/CodeTemplates/stubs/`

## Governing docs

- `rsx:man module_organization` - the module/feature ladder and the generator synopsis
- `rsx:man spa` - the shape the SPA stubs must produce
- `rsx:man auth_gates` - why every generated surface carries a gate

## Testable surface

| Concern | Type | Notes |
|---------|------|-------|
| Generated file SET per mode | php | The scaffolder writes into `rsx/app/` directly; tests use reserved module names and clean up in `finally`. |
| Load-bearing content lines | php | `#[Auth]`/`@auth` (a missing gate fails the next manifest build), `#[SPA]`, the `rsx_view(SPA, ['bundle' => ...])` parameter, `$sid="content"`, the four Action decorators, base classes. |
| Name validation + refusals | php | Illegal characters, an existing module, a feature in a missing module. |
| End-to-end render | manual / rsx:debug | A scaffolded module rendering under `rsx:debug` is verified by hand at change time; it needs a JIT bundle compile and a browser, so it is not a php test. |

## Notes

Generated output is only as correct as the next manifest build allows: a
scaffold that omits `#[Auth]` or names a bundle base that cannot hold a module
directory does not surface as a generator error, it surfaces as a build failure
in the developer's next command. That is why the content assertions live here.
