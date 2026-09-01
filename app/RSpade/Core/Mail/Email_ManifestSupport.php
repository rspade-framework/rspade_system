<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Mail;

use App\RSpade\Core\Manifest\ManifestSupport_Abstract;

/**
 * Support module that bakes the table of every email an application can send.
 *
 * An email is an Rsx_Email subclass co-located with the blade that renders it:
 *
 * ```php
 * class Welcome_Email extends Rsx_Email
 * {
 *     const CATEGORY = self::TRANSACTIONAL;
 *     public static function sample(): static { return new static(...); }
 * }
 * ```
 *
 * and the manifest records it here as
 *
 *     data['emails']['Welcome_Email'] = ['class' => ..., 'file' => ..., 'view_id' => ..., 'category' => 1]
 *
 * Discovery happens HERE, once, at build time. Everything downstream - the queue row's
 * email_class, the preview surface, rsx:mail:test - reads a baked table and decides
 * nothing.
 *
 * Every rule below is a manifest-build FATAL naming the file and the class. A
 * declaration that violates one breaks the build until it is fixed:
 *
 *   - No `const CATEGORY`. There is no safe default: TRANSACTIONAL mails people who
 *     opted out, NOTIFICATION silently drops mail somebody needed. The author decides.
 *   - No `public static function sample()`. An email nobody can preview is an email
 *     nobody reviews before it reaches a customer.
 *   - No blade carrying `@rsx_id('<ClassBasename>')`. The class name IS the template
 *     id; without one the send would fail at render time, in a background task, hours
 *     after the code shipped.
 *   - A CATEGORY that is not one of the three constants.
 *   - Two classes with the same basename - one basename names exactly one email.
 *
 * Constants are read from the SOURCE FILE, not by reflection: the manifest builds
 * before the autoloader can resolve application classes, and the file metadata indexes
 * methods and properties but not constants.
 */
class Email_ManifestSupport extends ManifestSupport_Abstract
{
    /**
     * The class every email extends (directly or through an application base class).
     */
    private const ROOT_CLASS = 'Rsx_Email_Abstract';

    /**
     * CATEGORY value => the constant name an author would have written.
     */
    private const CATEGORIES = [
        1 => 'TRANSACTIONAL',
        2 => 'NOTIFICATION',
        3 => 'MARKETING',
    ];

    public static function get_name(): string
    {
        return 'Emails';
    }

    public static function process(array &$manifest_data): void
    {
        $manifest_data['data']['emails'] = [];

        $files = $manifest_data['data']['files'];
        $by_class = static::_index_classes($files);
        $view_ids = static::_index_view_ids($files);

        $table = [];

        foreach ($files as $file => $metadata) {
            $class = $metadata['class'] ?? null;

            if ($class === null || $class === self::ROOT_CLASS) {
                continue;
            }

            if (!static::_descends_from_root($class, $by_class)) {
                continue;
            }

            // An application may put an abstract base of its own between Rsx_Email and
            // its concrete emails. Only a class somebody can actually instantiate owes
            // the contract.
            if (!empty($metadata['abstract'])) {
                continue;
            }

            $fqcn = $metadata['fqcn'] ?? $class;
            $location = "{$fqcn} in {$file}";

            $category = static::_read_category($file, $metadata, $location);
            static::_require_sample($metadata, $location);
            static::_require_view($class, $view_ids, $location);

            if (isset($table[$class])) {
                throw new \RuntimeException(
                    "Duplicate email class '{$class}': {$location}\n"
                    . "  Already declared by {$table[$class]['class']} in {$table[$class]['file']}.\n"
                    . '  A class basename names exactly one email - it is the blade id and the'
                    . " value stored in\n  email_queue.email_class."
                );
            }

            $table[$class] = [
                'class' => $fqcn,
                'file' => $file,
                'view_id' => $class,
                'category' => $category,
            ];
        }

        ksort($table);

        $manifest_data['data']['emails'] = $table;
    }

    /**
     * class basename => file metadata, for walking inheritance chains.
     *
     * @param array $files
     * @return array<string, array>
     */
    private static function _index_classes(array $files): array
    {
        $by_class = [];

        foreach ($files as $metadata) {
            if (!empty($metadata['class'])) {
                $by_class[$metadata['class']] = $metadata;
            }
        }

        return $by_class;
    }

    /**
     * Every @rsx_id a blade template in this build declares => the blade's file.
     *
     * @param array $files
     * @return array<string, string>
     */
    private static function _index_view_ids(array $files): array
    {
        $ids = [];

        foreach ($files as $file => $metadata) {
            if (($metadata['type'] ?? null) === 'view' && !empty($metadata['id'])) {
                $ids[$metadata['id']] = $file;
            }
        }

        return $ids;
    }

    /**
     * Whether $class reaches Rsx_Email by following `extends`.
     *
     * @param string $class
     * @param array<string, array> $by_class
     * @return bool
     */
    private static function _descends_from_root(string $class, array $by_class): bool
    {
        $seen = [];
        $current = $class;

        while (true) {
            if (isset($seen[$current])) {
                return false;   // a cycle; the class-graph validator owns that complaint
            }

            $seen[$current] = true;

            $parent = $by_class[$current]['extends'] ?? null;

            if ($parent === null) {
                return false;
            }

            if ($parent === self::ROOT_CLASS) {
                return true;
            }

            $current = $parent;
        }
    }

    /**
     * The declared `const CATEGORY`, read from the source file.
     *
     * @param string $file
     * @param array $metadata
     * @param string $location
     * @return int
     */
    private static function _read_category(string $file, array $metadata, string $location): int
    {
        $source = file_get_contents(base_path($file));

        if ($source === false) {
            throw new \RuntimeException("Unable to read email class source: {$location}");
        }

        $matched = preg_match('/\bconst\s+CATEGORY\s*=\s*([^;]+);/', $source, $match);

        if (!$matched) {
            throw new \RuntimeException(
                "Email class has no CATEGORY: {$location}\n"
                . "  Every email declares which category it belongs to - it decides whether an\n"
                . "  unsubscribed recipient still receives it, and there is no safe default:\n"
                . "\n"
                . "      const CATEGORY = self::TRANSACTIONAL;   // the recipient's own action asked for it\n"
                . "      const CATEGORY = self::NOTIFICATION;    // respects the notification opt-out\n"
                . "      const CATEGORY = self::MARKETING;       // respects the marketing opt-out\n"
            );
        }

        $expression = trim($match[1]);
        $category = null;

        foreach (self::CATEGORIES as $value => $name) {
            if (preg_match('/(?:self|static|Rsx_Email_Abstract)::' . $name . '\b/', $expression)
                || $expression === (string) $value) {
                $category = $value;
                break;
            }
        }

        if ($category === null) {
            throw new \RuntimeException(
                "Email class has an unrecognized CATEGORY: {$location}\n"
                . "  const CATEGORY = {$expression};\n"
                . '  It must be one of self::TRANSACTIONAL, self::NOTIFICATION, self::MARKETING.'
            );
        }

        return $category;
    }

    /**
     * Every concrete email owes a sample() - the preview and the test suite both build
     * one, and an email that cannot be constructed for review is reviewed by nobody.
     *
     * @param array $metadata
     * @param string $location
     * @return void
     */
    private static function _require_sample(array $metadata, string $location): void
    {
        if (isset($metadata['public_static_methods']['sample'])) {
            return;
        }

        throw new \RuntimeException(
            "Email class has no sample(): {$location}\n"
            . "  Add a public static sample() returning a fully-constructed instance:\n"
            . "\n"
            . "      public static function sample(): static\n"
            . "      {\n"
            . "          return new static(/* plausible values - avoid the database */);\n"
            . "      }\n"
        );
    }

    /**
     * The blade that renders this email. Its @rsx_id IS the class basename.
     *
     * @param string $class
     * @param array<string, string> $view_ids
     * @param string $location
     * @return void
     */
    private static function _require_view(string $class, array $view_ids, string $location): void
    {
        if (isset($view_ids[$class])) {
            return;
        }

        throw new \RuntimeException(
            "Email class has no template: {$location}\n"
            . "  No blade in this build declares @rsx_id('{$class}').\n"
            . "  Create the template beside the class and give it that id - the class name IS\n"
            . '  the template id, so a mismatch would only surface when the send task renders.'
        );
    }
}
