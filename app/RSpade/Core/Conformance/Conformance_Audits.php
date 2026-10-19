<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Conformance;

use RuntimeException;

/**
 * Conformance audits: the library of review prompts for contracts no rule can enforce.
 *
 * A conformance audit is one markdown file describing a contract an application's code must
 * honour - the shape an Rsx_Form input implements, a GET handler that writes nothing, a
 * money amount never held in a float - written as a PROMPT an agent follows to review the
 * application and report what does not conform. They are never run automatically and are
 * not tests: a developer asks for them ("run the conformance audits", "run the launch
 * audits"), and one read-only agent walks each.
 *
 * TWO HOMES, ONE NAMESPACE. The framework's audits ship in system/app/RSpade/conformance/;
 * an application adds its own in rsx/resource/conformance/. An id is unique across both - an
 * application audit is an addition, never a replacement, and a repeated id throws. Every
 * `*.md` file in either directory is an audit except `CLAUDE.md`, the directory's description.
 *
 * THE FILE. A header between two `---` lines, one `key: value` per line, then the prompt:
 *
 *     ---
 *     id: unbounded_queries                 (required; equals the file's basename)
 *     title: Result sets with no ceiling    (required)
 *     severity: high                        (required: critical | high | medium | low)
 *     when: launch, change                  (required: one or more of launch, change, periodic)
 *     scope: rsx/**\/*.php                   (required: comma-separated globs from the project root)
 *     related: rsx:man model, skill rspade:bounded-result-sets   (optional, comma-separated)
 *     ---
 *
 * `when` says when the audit is worth running: `launch` before a site goes live, `change`
 * whenever code in its scope is written or changed, `periodic` on a schedule of the owner's
 * choosing. `scope` is what for_path() matches: `**` crosses directories, `*` and `?` stay
 * within one. A header that is malformed, incomplete or names an unknown key throws.
 *
 * See rsx:man conformance_audits.
 */
class Conformance_Audits
{
    public const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    public const OCCASIONS = ['launch', 'change', 'periodic'];

    private const REQUIRED_KEYS = ['id', 'title', 'severity', 'when', 'scope'];

    private const OPTIONAL_KEYS = ['related'];

    /**
     * Test seam: the directories to read instead of the two homes, as [origin => path].
     *
     * @var array<string, string>|null
     */
    public static ?array $_directories_for_tests = null;

    /**
     * The two directories audits are read from, as [origin => absolute path].
     *
     * @return array<string, string>
     */
    public static function directories(): array
    {
        if (static::$_directories_for_tests !== null) {
            return static::$_directories_for_tests;
        }

        return [
            'framework' => base_path('app/RSpade/conformance'),
            'application' => dirname(base_path()) . '/rsx/resource/conformance',
        ];
    }

    /**
     * Every audit, framework first, each ordered by id.
     *
     * @return array<int, array{id: string, title: string, severity: string, when: array, scope: array, related: array, origin: string, file: string, body: string}>
     * @throws RuntimeException A malformed audit, or an id declared twice
     */
    public static function all(): array
    {
        $audits = [];
        $seen = [];

        foreach (static::directories() as $origin => $directory) {
            $files = is_dir($directory) ? glob($directory . '/*.md') : [];
            sort($files);

            foreach ($files as $file) {
                // The directory's own living description, not an audit.
                if (basename($file) === 'CLAUDE.md') {
                    continue;
                }

                $audit = static::parse($file, $origin);

                if (isset($seen[$audit['id']])) {
                    throw new RuntimeException(
                        "Conformance audit id '{$audit['id']}' is declared twice: {$seen[$audit['id']]} and {$file}. "
                        . 'An application audit adds to the framework library and never replaces one - give it its own id.'
                    );
                }

                $seen[$audit['id']] = $file;
                $audits[] = $audit;
            }
        }

        return $audits;
    }

    /**
     * One audit by id.
     *
     * @param string $id
     * @return array
     * @throws RuntimeException No audit has that id
     */
    public static function find(string $id): array
    {
        foreach (static::all() as $audit) {
            if ($audit['id'] === $id) {
                return $audit;
            }
        }

        throw new RuntimeException("No conformance audit has the id '{$id}'. php artisan rsx:conformance:list lists them.");
    }

    /**
     * The audits whose scope covers a file.
     *
     * @param string $path A path relative to the project root (rsx/app/..., system/app/...), or absolute
     * @return array
     */
    public static function for_path(string $path): array
    {
        $root = dirname(base_path()) . '/';
        $relative = str_starts_with($path, $root) ? substr($path, strlen($root)) : ltrim($path, './');

        return array_values(array_filter(static::all(), static function (array $audit) use ($relative): bool {
            foreach ($audit['scope'] as $glob) {
                if (preg_match(static::glob_regex($glob), $relative)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * A scope glob as a regular expression: `**` any run of characters across directories
     * (`**\/` also matches no directory at all), `*` and `?` within one path segment.
     *
     * @param string $glob
     * @return string
     */
    public static function glob_regex(string $glob): string
    {
        $regex = '';
        $length = strlen($glob);

        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];

            if ($char === '*' && ($glob[$i + 1] ?? '') === '*') {
                if (($glob[$i + 2] ?? '') === '/') {
                    $regex .= '(?:.*/)?';
                    $i += 2;
                } else {
                    $regex .= '.*';
                    $i += 1;
                }
            } elseif ($char === '*') {
                $regex .= '[^/]*';
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($char, '#');
            }
        }

        return '#^' . $regex . '$#';
    }

    /**
     * Read and validate one audit file.
     *
     * @param string $file
     * @param string $origin 'framework' or 'application'
     * @return array
     * @throws RuntimeException
     */
    public static function parse(string $file, string $origin): array
    {
        $contents = (string) file_get_contents($file);

        if (!preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $contents, $matches)) {
            throw new RuntimeException("Conformance audit {$file} does not open with a header between two '---' lines.");
        }

        $header = [];

        foreach (explode("\n", $matches[1]) as $number => $line) {
            if (trim($line) === '') {
                continue;
            }

            if (!preg_match('/^([a-z_]+):\s*(.*)$/', $line, $pair)) {
                throw new RuntimeException("Conformance audit {$file}, header line " . ($number + 1) . ": expected 'key: value', got '{$line}'.");
            }

            if (!in_array($pair[1], self::REQUIRED_KEYS, true) && !in_array($pair[1], self::OPTIONAL_KEYS, true)) {
                throw new RuntimeException("Conformance audit {$file} has an unknown header key '{$pair[1]}'. Keys: " . implode(', ', array_merge(self::REQUIRED_KEYS, self::OPTIONAL_KEYS)) . '.');
            }

            if (isset($header[$pair[1]])) {
                throw new RuntimeException("Conformance audit {$file} declares '{$pair[1]}' twice.");
            }

            $header[$pair[1]] = trim($pair[2]);
        }

        foreach (self::REQUIRED_KEYS as $key) {
            if (($header[$key] ?? '') === '') {
                throw new RuntimeException("Conformance audit {$file} has no '{$key}' in its header.");
            }
        }

        if ($header['id'] !== basename($file, '.md')) {
            throw new RuntimeException("Conformance audit {$file}: the id '{$header['id']}' must equal the file's name.");
        }

        if (!in_array($header['severity'], self::SEVERITIES, true)) {
            throw new RuntimeException("Conformance audit {$file}: severity '{$header['severity']}' is not one of " . implode(', ', self::SEVERITIES) . '.');
        }

        $when = static::__list($header['when']);
        foreach ($when as $occasion) {
            if (!in_array($occasion, self::OCCASIONS, true)) {
                throw new RuntimeException("Conformance audit {$file}: when '{$occasion}' is not one of " . implode(', ', self::OCCASIONS) . '.');
            }
        }

        return [
            'id' => $header['id'],
            'title' => $header['title'],
            'severity' => $header['severity'],
            'when' => $when,
            'scope' => static::__list($header['scope']),
            'related' => static::__list($header['related'] ?? ''),
            'origin' => $origin,
            'file' => $file,
            'body' => ltrim($matches[2], "\n"),
        ];
    }

    /**
     * A comma-separated header value as a list.
     *
     * @param string $value
     * @return array<int, string>
     */
    private static function __list(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($item) => $item !== ''));
    }
}
