<?php
/**
 * JavaScript and CSS bundle concatenation
 *
 * Joins the files of one bundle into a single artifact and merges their sourcemaps
 * (Mozilla source-map: consumer/generator/SourceNode). The work is node's because the
 * sourcemap merging is - reimplementing SourceNode in PHP is not on the table - and it
 * runs over a persistent RPC daemon like every other node helper in the framework.
 *
 * WHY THIS IS AN RPC CLIENT AND NOT A SHELL-OUT. It used to be one: the compiler built
 * `node concat-js.js <output> <file> <file> ...` as a single shell string and handed it to
 * exec_safe(). Linux caps ONE argument at MAX_ARG_STRLEN (32 pages = 131072 bytes), so a
 * bundle's file list eventually cannot be spelled on a command line at all - and the
 * failure is a cliff, not a gradient: one byte under works, one byte over fails every
 * build. A downstream app hit it at 1,310 bundled JS files (129,219 bytes of argv, ~18
 * files of headroom) and EVERY route in that application returned 500, blaming the
 * innocent file whose addition tipped it over. See helpers.php's ARG_MAX_SINGLE guard for
 * the loud version of that failure, and rsx:man bundle_api for the contract.
 *
 * A socket has no such ceiling: the file list is one JSON line and the payload scales with
 * the app for free. So this is not a performance change - the spawn cost was already paid
 * down by the RPC migration, and a warm build barely notices - it is the removal of a
 * structural limit.
 *
 * The work runs over the `concat` subsystem of the node service (Rsx_Node_Service), which
 * is spawned on demand and reused; a page render that JIT-compiles a bundle starts it
 * exactly as Minifier (its sibling in this directory, called from the same path) does.
 */

namespace App\RSpade\Core\Bundle;

use RuntimeException;
use App\RSpade\Core\JsParsers\Rsx_Node_Service;

class Concatenator
{
    /**
     * Concatenate JavaScript files into one bundle artifact, merging sourcemaps.
     *
     * Each entry of $files is ['path' => <file to READ>, 'source' => <path to ATTRIBUTE it
     * to>|null]. The two differ for a babel-transformed file: node reads the transformed
     * temp file but names the original in the banner and the sourcemap, so a developer
     * lands on their own source. A null 'source' means the two are the same file.
     *
     * Node writes $output_file and reports what it did; the caller reads the file.
     *
     * @param array $files Ordered list of ['path' => string, 'source' => ?string]
     * @param string $output_file Absolute path node writes the concatenated bundle to
     * @return array {files, bytes, sourcemap_bytes, sources, warnings}
     */
    public static function concat_js(array $files, string $output_file): array
    {
        return static::_concat_via_rpc('js', $files, $output_file);
    }

    /**
     * Concatenate CSS files into one bundle artifact, merging sourcemaps.
     *
     * CSS has no babel stage, so every entry is simply ['path' => string]; a 'source' is
     * accepted and honored for symmetry.
     *
     * @param array $files Ordered list of ['path' => string, 'source' => ?string]
     * @param string $output_file Absolute path node writes the concatenated bundle to
     * @return array {files, bytes, sourcemap_bytes, sources, warnings}
     */
    public static function concat_css(array $files, string $output_file): array
    {
        return static::_concat_via_rpc('css', $files, $output_file);
    }

    /**
     * Concatenate via the node service.
     *
     * One request, one line of JSON, one response line - the framing every subsystem of the
     * service uses. The file list rides in the payload, which is the whole point.
     */
    protected static function _concat_via_rpc(string $type, array $files, string $output_file): array
    {
        if (empty($files)) {
            throw new RuntimeException(
                'Concatenator::concat_' . $type . '() was given no files. A bundle with nothing '
                . 'in it is a compiler bug, not an empty artifact.'
            );
        }

        $payload_files = [];
        foreach ($files as $file) {
            if (!is_array($file) || !isset($file['path'])) {
                throw new RuntimeException(
                    'Concatenator: every file entry must be an array carrying a "path" key.'
                );
            }

            $payload_files[] = [
                'path' => $file['path'],
                'source' => $file['source'] ?? null,
            ];
        }

        // No connect timeout, for the reason Minifier records at its own call site: a request
        // that queues behind a large one already in flight is waiting on real work, and
        // slowness is never evidence of a hang. Every read has always been unbounded too.
        $result = Rsx_Node_Service::request('concat.concat', [
            'type' => $type,
            'output_file' => $output_file,
            'files' => $payload_files,
        ]);

        if (!isset($result['result'])) {
            throw new RuntimeException(
                'Invalid response from the concat RPC: ' . substr(json_encode($result), 0, 500)
            );
        }

        $file_result = $result['result'];

        if (($file_result['status'] ?? null) === 'error') {
            $error = $file_result['error'] ?? [];
            $message = $error['message'] ?? 'unknown error';

            // The offending file is named by the node side whenever it knows which one it
            // was, so this message is at least as informative as the shell-out's was.
            throw new RuntimeException(
                'Failed to concatenate ' . strtoupper($type) . ' files: ' . $message
            );
        }

        if (($file_result['status'] ?? null) !== 'success') {
            throw new RuntimeException(
                'The concat RPC returned no status for ' . $output_file
            );
        }

        return [
            'files' => (int) ($file_result['files'] ?? 0),
            'bytes' => (int) ($file_result['bytes'] ?? 0),
            'sourcemap_bytes' => (int) ($file_result['sourcemap_bytes'] ?? 0),
            'sources' => $file_result['sources'] ?? [],
            'warnings' => $file_result['warnings'] ?? [],
        ];
    }
}
