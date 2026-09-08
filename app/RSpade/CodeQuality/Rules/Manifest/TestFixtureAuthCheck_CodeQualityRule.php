<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\CodeQuality\Support\FileSanitizer;
use App\RSpade\Core\Naming\Rsx_Paths;

/**
 * TEST-AUTH-01 - a test fixture may only name auth checks the framework itself provides.
 *
 * The test trees are scanned into a manifest whenever the suite runs, and a fixture that
 * carries a real `#[Auth(...)]` or `@auth(...)` declaration is indexed exactly like any
 * other surface. Closed-by-default validation then resolves every named check against the
 * realm's registry - and the registry an install has is the one ITS application defines.
 *
 * A fixture naming an application check therefore builds here and FAILS THE BUILD on any
 * install whose application does not define that name. That is not a test failure, it is a
 * hard-down site: the manifest is what boots the framework.
 *
 * So the vocabulary a fixture may use is the vocabulary the framework itself guarantees:
 * the four checks declared on Permission_Abstract / Portal_Permission_Abstract.
 * 'is_sysadmin' is staff-only; the other three exist in both realms.
 *
 * Scope is any `tests/` directory under the framework tree or the application tree, which
 * is exactly the set of files that ships as somebody else's fixture.
 */
class TestFixtureAuthCheck_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'TEST-AUTH-01';

    /**
     * The checks the FRAMEWORK declares, and therefore the only ones a fixture may name.
     * See App\RSpade\Core\Permission\Permission_Abstract (staff) and
     * App\RSpade\Core\Portal\Portal_Permission_Abstract (portal).
     */
    private const FRAMEWORK_CHECKS = ['public', 'closed', 'is_logged_in', 'is_sysadmin'];

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Test Fixture Auth Check Vocabulary';
    }

    public function get_description(): string
    {
        return 'An #[Auth(...)] or @auth(...) declaration inside a tests/ directory may only name a '
            . 'check the framework itself provides';
    }

    public function get_file_patterns(): array
    {
        return ['*.php', '*.js'];
    }

    /**
     * Blocking: the consequence downstream is a manifest build that refuses to complete,
     * which takes the whole site down.
     */
    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Manifest-time, because the manifest build is the thing that breaks.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        if (!$this->__is_test_tree_file($file_path)) {
            return;
        }

        $declarations = str_ends_with($file_path, '.js')
            ? $this->__js_declarations($contents)
            : $this->__php_declarations($contents);

        foreach ($declarations as [$name, $line_number, $spelling]) {
            if (in_array($name, self::FRAMEWORK_CHECKS, true)) {
                continue;
            }

            $this->__report($file_path, $line_number, $contents, $name, $spelling);
        }
    }

    /**
     * Is this file a fixture - a file inside a `tests/` directory of the framework tree or
     * the application tree? Paths arrive both relative (`app/RSpade/tests/...`, from the
     * manifest) and absolute (from rsx:check and the IDE).
     */
    private function __is_test_tree_file(string $file_path): bool
    {
        $normalized = str_replace('\\', '/', $file_path);

        if (!str_contains($normalized, '/tests/')) {
            return false;
        }

        return Rsx_Paths::is_framework($normalized) || Rsx_Paths::is_application($normalized);
    }

    /**
     * Every check name named by a real `#[Auth(...)]` ATTRIBUTE, as [name, line, spelling].
     *
     * Tokenizing is what makes this exact: an attribute opens a T_ATTRIBUTE token, so the
     * same characters sitting inside a string literal (a fixture SOURCE that a validation
     * test writes to a temp file) or inside a comment can never be mistaken for one. That
     * distinction is the whole difference between a rule and a false-positive generator.
     *
     * @return array<int,array{0:string,1:int,2:string}>
     */
    private function __php_declarations(string $contents): array
    {
        $tokens = $this->source()->php_tokens_of($contents);
        $names = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== T_ATTRIBUTE) {
                continue;
            }

            // Walk the attribute group to its matching ']', collecting Auth(...) arguments.
            $depth = 1;
            $in_auth = false;
            $auth_depth = 0;

            for ($j = $i + 1; $j < $count && $depth > 0; $j++) {
                $token = $tokens[$j];
                $text = $token->text;

                if ($text === '[' || $text === '(') {
                    $depth++;
                    continue;
                }

                if ($text === ']' || $text === ')') {
                    $depth--;
                    if ($in_auth && $depth < $auth_depth) {
                        $in_auth = false;
                    }
                    continue;
                }

                if ($token->id === T_STRING && $text === 'Auth' && !$in_auth) {
                    $in_auth = true;
                    $auth_depth = $depth + 1;
                    continue;
                }

                if ($in_auth && $token->id === T_CONSTANT_ENCAPSED_STRING) {
                    $names[] = [
                        trim($text, "'\""),
                        $token->line,
                        "#[Auth(...)]",
                    ];
                }
            }

            $i = $j - 1;
        }

        return $names;
    }

    /**
     * Every check name named by an `@auth(...)` DECORATOR, as [name, line, spelling].
     *
     * A decorator always occupies its own line above the class it decorates, so the line
     * anchor is the structure. Comments are blanked first, and the checker's own sanitizer
     * has already blanked string contents by the time a real build reaches this.
     *
     * @return array<int,array{0:string,1:int,2:string}>
     */
    private function __js_declarations(string $contents): array
    {
        $lines = explode("\n", FileSanitizer::blank_js_comments($contents));
        $names = [];

        foreach ($lines as $index => $line) {
            if (!preg_match('/^\s*@auth\s*\((.*)\)/', $line, $matches)) {
                continue;
            }

            if (preg_match_all('/[\'"]([^\'"]*)[\'"]/', $matches[1], $arguments)) {
                foreach ($arguments[1] as $name) {
                    $names[] = [$name, $index + 1, "@auth(...)"];
                }
            }
        }

        return $names;
    }

    private function __report(string $file_path, int $line_number, string $contents, string $name, string $spelling): void
    {
        $this->add_violation(
            $file_path,
            $line_number,
            "Test fixture {$spelling} names '{$name}', which is not a check the framework provides.",
            $this->__snippet($contents, $line_number),
            "A test fixture is scanned into every install's manifest; it may only name checks the "
                . "framework itself provides.\n"
                . "\n"
                . "The suite's manifest indexes this declaration as a real surface, and closed-by-default "
                . "validation resolves '{$name}' against the realm registry of whatever application is "
                . "installed. Where that application does not declare '{$name}', the MANIFEST BUILD fails - "
                . "and a failed manifest build is a hard-down site, not a failing test.\n"
                . "\n"
                . "FIX: use one of the framework's own checks - " . implode(', ', self::FRAMEWORK_CHECKS)
                . " ('is_sysadmin' is staff-only). A fixture that must exercise an application check does "
                . "so with SYNTHETIC metadata handed to the auth index, never with a live attribute.\n"
                . "\n"
                . "Rule: " . self::RULE_ID . ". See: rsx:man auth_gates",
            $this->get_default_severity()
        );
    }

    private function __snippet(string $contents, int $line_number): string
    {
        $lines = explode("\n", $contents);

        return isset($lines[$line_number - 1]) ? trim($lines[$line_number - 1]) : '';
    }
}
