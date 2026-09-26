<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Debug;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The console_debug() channel inventory: every channel name a console_debug() call in
 * the source spells as a string literal.
 *
 * The ONE scanner. `rsx:console_debug:list_channels` prints it, and the /_sys Debug
 * Flags screen offers it as the channel picker. It is a regex over the files under
 * system/app and rsx/ read at call time - no cache, so it is paid only by a caller that
 * asks for it (the command, or the screen's own endpoint), never by an ordinary request.
 * A channel built at run time (a variable, a template literal with ${}) is not found.
 */
class Console_Debug_Channels
{
    /** Extensions scanned as PHP. */
    public const PHP_EXTENSIONS = ['php'];

    /** Extensions scanned as JavaScript. */
    public const JS_EXTENSIONS = ['js', 'jsx', 'ts', 'tsx'];

    /**
     * Every channel found, sorted by name, with its call count per language.
     *
     * @param bool $php Scan PHP files
     * @param bool $js Scan JavaScript files
     * @return array<string, array{php: int, js: int}> channel => counts
     */
    public static function scan(bool $php = true, bool $js = true): array
    {
        $channels = [];

        foreach (static::directories() as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $extension = $file->getExtension();

                if ($php && in_array($extension, self::PHP_EXTENSIONS, true)) {
                    // console_debug("CHANNEL", ...) or console_debug('CHANNEL', ...)
                    static::__count(file_get_contents($file->getRealPath()), '/console_debug\s*\(\s*[\'"]([^\'",]+)[\'"]/', 'php', $channels);
                } elseif ($js && in_array($extension, self::JS_EXTENSIONS, true)) {
                    // The same, plus a backtick literal with no ${} in it
                    static::__count(file_get_contents($file->getRealPath()), '/console_debug\s*\(\s*[\'"`]([^\'"`\$,]+)[\'"`]/', 'js', $channels);
                }
            }
        }

        ksort($channels);

        return $channels;
    }

    /**
     * The trees scanned: the framework's app/ and the application's rsx/.
     *
     * @return string[]
     */
    public static function directories(): array
    {
        return [base_path('app'), base_path('rsx')];
    }

    /**
     * Add each channel the pattern captures in $content to $channels under $language.
     */
    private static function __count(string $content, string $pattern, string $language, array &$channels): void
    {
        if (!preg_match_all($pattern, $content, $matches)) {
            return;
        }

        foreach ($matches[1] as $channel) {
            $channel = strtoupper(str_replace(['[', ']'], '', $channel));

            if (!isset($channels[$channel])) {
                $channels[$channel] = ['php' => 0, 'js' => 0];
            }

            $channels[$channel][$language]++;
        }
    }
}
