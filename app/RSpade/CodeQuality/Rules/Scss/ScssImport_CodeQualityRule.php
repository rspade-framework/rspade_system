<?php

namespace App\RSpade\CodeQuality\Rules\Scss;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Integrations\Scss\Scss_BundleProcessor;

/**
 * SCSS-IMPORT-01 - an authored stylesheet does not `@import`.
 *
 * A stylesheet reaches the browser by being INCLUDED in a bundle, which owns the order the
 * files compile in and what the bundle depends on. An `@import "partial"` goes around both:
 * the partial compiles wherever the importing file happens to sit, a second import of it
 * compiles it twice, and nothing in the manifest knows the dependency exists. The bundle
 * compiler has always refused such a file before Sass runs; this rule refuses it when the
 * file is SAVED, so the error arrives with the edit rather than with the next page load
 * that compiles a bundle containing it.
 *
 * The predicate is Scss_BundleProcessor::forbidden_imports(), the one the compiler applies:
 * a quoted `@import "..."` outside a comment, in a .scss / .sass file whose path has no
 * `/vendor/` directory (third-party source keeps its own imports). A CSS `@import url(...)`
 * is not one - the compiler localizes a remote stylesheet imported that way.
 *
 * Fatal by design, so not suppressible: the compiler would refuse the file regardless.
 *
 * See: php artisan rsx:man scss
 */
class ScssImport_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'SCSS-IMPORT-01';
    }

    public function get_name(): string
    {
        return 'No @import In Authored Stylesheets';
    }

    public function get_description(): string
    {
        return 'A non-vendor .scss/.sass file may not @import another stylesheet; files reach a bundle through its include list';
    }

    public function get_file_patterns(): array
    {
        return ['*.scss', '*.sass'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Manifest-time by framework-maintainer decision: the bundle compiler refuses the same
     * file, so reporting it at the edit costs nothing that was not already lost.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        if (str_contains($file_path, '/node_modules/')) {
            return;
        }

        $imports = Scss_BundleProcessor::forbidden_imports($file_path, $contents);

        if ($imports === []) {
            return;
        }

        $lines = explode("\n", $contents);

        foreach ($imports as $import) {
            $this->add_violation(
                $file_path,
                $import['line'],
                "{$import['text']}: an authored stylesheet may not @import another stylesheet.",
                trim($lines[$import['line'] - 1] ?? $import['text']),
                Scss_BundleProcessor::forbidden_import_remedy() . "\n\nSee: php artisan rsx:man scss",
                'critical'
            );
        }
    }
}
