<?php

namespace App\RSpade\CodeQuality\Rules\Convention;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Class_Override_Drift;

/**
 * CLASS-OVERRIDE-DRIFT-01 - an application's class override has stopped carrying a member
 * the framework class it replaced still declares.
 *
 * A class override is a COPY, and a copy is frozen the moment it is taken. The framework
 * file it replaced is archived to <Name>.php.upstream and keeps moving with every pull.
 * When the framework then adds a member and CALLS it - core calling into a class it
 * believes is its own - the call lands on the application's older copy. A downstream field
 * report records that exact shape: core called a member of a class the app had replaced,
 * the copy predated the member, and one path answered 500 while another silently enqueued
 * nothing for months. Nothing compared the two files, so nothing said a word.
 *
 * This rule is the comparison. For every archived sidecar it names each public or
 * protected member present upstream and absent from the override, one finding per member,
 * and prints the override's OWN additions alongside as context - because the reader's next
 * move is to re-clone from the sidecar and re-apply exactly those additions, and they have
 * to know what they are.
 *
 * WHY HIGH AND NOT A BUILD FATAL. The drift is real and it is silent, so it must be loud
 * in rsx:check and visible in rsx:health. But it arrives on a framework PULL, not on
 * anything the developer just typed, and the site it appears on is running: taking that
 * site down until somebody finds time to re-clone would convert a latent defect into an
 * immediate outage. The finding is the alarm; the developer schedules the re-clone.
 *
 * WHAT IT DOES NOT SEE. A member present in both files whose BODY has diverged is not
 * drift this rule can name - it is a deliberate override in the ordinary case and an
 * ordinary stale copy in the bad one, and the two are indistinguishable from the outside.
 * The member list is the part that has a right answer.
 *
 * Suppress on a single override with @CLASS-OVERRIDE-DRIFT-01-EXCEPTION in the override
 * file, which is a statement that the app has deliberately dropped what upstream declares.
 *
 * @see \App\RSpade\Core\Manifest\Class_Override_Drift - the analysis.
 * @see rsx:man class_override
 */
class ClassOverrideDrift_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'CLASS-OVERRIDE-DRIFT-01';

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Class Override Drift';
    }

    public function get_description(): string
    {
        return 'A class override must still declare every public or protected member the framework '
            . 'class it replaced declares';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    /**
     * High, not critical: silent and consequential, but it arrives on a framework pull and
     * the fix is a scheduled re-clone, not an edit to what the developer just wrote.
     */
    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Never at manifest-build time. A build fatal here would take a running development
     * site down the moment a pull landed, for a defect that predates the pull.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return false;
    }

    /**
     * CROSS-FILE: this rule judges the tree, not one file. The driver runs it once per
     * pass, gated on the fingerprint of what depends_on() declares.
     */
    public function kind(): string
    {
        return self::KIND_CROSS_FILE;
    }

    /**
     * Every indexed PHP file plus the archived .upstream twins: drift is the difference
     * between an override and the framework file it shadows.
     *
     * @return array<int,string>
     */
    public function depends_on(): array
    {
        return [
            'files:*.php',
            'files:*.upstream',
        ];
    }

    /**
     * Runs once per pass, over the whole manifest, regardless of which file triggered it.
     */
    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        static $already_checked = false;

        if ($already_checked) {
            return;
        }

        $already_checked = true;

        foreach (Class_Override_Drift::pairs() as $pair) {
            $this->evaluate_pair(
                $pair['class'],
                base_path($pair['upstream_file']),
                base_path($pair['override_file'])
            );
        }
    }

    /**
     * Compare ONE override against ONE archived sidecar and report what is missing.
     *
     * This is the testable seam: production supplies the pairs from the manifest, a test
     * supplies two fixture files that are in no manifest at all.
     *
     * @param string $class_name    The simple class name both files declare.
     * @param string $upstream_path Absolute path to the .php.upstream sidecar.
     * @param string $override_path Absolute path to the application's copy.
     */
    public function evaluate_pair(string $class_name, string $upstream_path, string $override_path): void
    {
        $analysis = Class_Override_Drift::analyze_pair($upstream_path, $override_path, $class_name);

        if (empty($analysis['missing'])) {
            return;
        }

        $override_source = $this->source()->content($override_path);

        // The checker's file-level exception check runs against whichever file triggered
        // this pass, which is never the override. The marker has to be read here.
        if ($override_source !== false && str_contains($override_source, '@' . self::RULE_ID . '-EXCEPTION')) {
            return;
        }

        $lines = $override_source === false ? [] : explode("\n", $override_source);
        $class_line = $override_source === false
            ? 0
            : Class_Override_Drift::class_declaration_line($override_source, $class_name);
        $snippet = ($class_line > 0 && isset($lines[$class_line - 1])) ? trim($lines[$class_line - 1]) : '';

        $context = $this->__additions_context($analysis['added']);
        $upstream_label = $this->__relative($upstream_path);
        $override_label = $this->__relative($override_path);

        foreach ($analysis['missing'] as $member) {
            $label = Class_Override_Drift::describe($member);

            $message = "{$class_name} overrides a framework class, and the framework's copy declares "
                . "{$label} at line {$member['line']} of the archived file. This override does not.\n\n"
                . "Upstream: {$upstream_label}\n"
                . "Override: {$override_label}\n\n"
                . 'Framework code may call this member on this class - core calling into a class it '
                . 'believes is its own - and it is not there. That is a fatal at the call site, or, '
                . 'when the member is a hook the framework only invokes, silence.';

            $suggestion = "Re-clone {$class_name} from the archived file and re-apply this "
                . "application's own additions:\n\n"
                . "    cp {$upstream_label} {$override_label}\n\n"
                . 'Then edit the namespace back and put the additions below back in. Enumerate them '
                . "in a banner at the top of the override so the next re-clone is mechanical.\n\n"
                . "Better, where the addition is POLICY rather than logic: move it to a seam and drop "
                . "the override entirely. Staff_Authorizable (can_view()/scope_can_view()), "
                . "Portal_Authorizable (portal_can_read()), the model's own fetch() policy and "
                . "#[OnEvent] handlers all extend a framework class without holding a copy of it.\n\n"
                . $context;

            $this->add_violation(
                $override_path,
                $class_line > 0 ? $class_line : 1,
                $message,
                $snippet,
                $suggestion
            );
        }
    }

    /**
     * A path as the manifest spells it - relative to the framework base path - so a
     * finding reads the way the build's own notices do.
     */
    private function __relative(string $absolute): string
    {
        $prefix = base_path() . '/';

        return str_starts_with($absolute, $prefix) ? substr($absolute, strlen($prefix)) : $absolute;
    }

    /**
     * The override's own additions, rendered for the finding. INFO context, never a
     * problem: this is the work the override exists to hold, and a re-clone has to carry
     * every line of it forward.
     *
     * @param array $added
     */
    private function __additions_context(array $added): string
    {
        if (empty($added)) {
            return 'INFO: this override declares nothing the framework file does not. It is a pure '
                . 'stale copy - deleting it restores the framework class and loses nothing.';
        }

        $rendered = [];
        foreach ($added as $member) {
            $rendered[] = '    ' . Class_Override_Drift::describe($member) . '   (line ' . $member['line'] . ')';
        }

        return "INFO: this override's own additions, which a re-clone must carry forward:\n"
            . implode("\n", $rendered);
    }

}
