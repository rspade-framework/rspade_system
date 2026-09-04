<?php
/**
 * SCSS compilation client
 *
 * There is exactly one sass invocation in the framework and this is it: the bundle build
 * assembles a master file of @imports and compiles it here, and a caller with a single
 * stylesheet that never reaches a browser (the email stylesheet, Rsx_Mail_Builder) compiles
 * through the same door.
 *
 * WHY THIS IS AN RPC CLIENT. Until now this step generated a fresh `compile.js` into a temp
 * directory on every call, ran `bash -c 'cd <base> && node <script>'`, recovered the exit
 * code by parsing it off the last line of captured output, and deleted the script again -
 * once per bundle, per build. It was the last survivor of the framework's pre-RPC
 * architecture, and the only node call site still shaped that way. The `scss` subsystem of
 * the node service (Rsx_Node_Service) keeps sass loaded between calls and the
 * write-then-shell-out-then-delete dance disappears entirely.
 *
 * This is not a speed claim. The framework's earlier RPC migration already paid down the
 * spawn cost that mattered (1,200+ spawns per clean build); what is left here is a handful
 * of invocations per build. The case is one architecture instead of two, and an error that
 * arrives as a value instead of as the last line of a captured string.
 */

namespace App\RSpade\Integrations\Scss;

use RuntimeException;
use App\RSpade\Core\JsParsers\Rsx_Node_Service;

class Scss_Compiler
{
    /**
     * Compile one SCSS entry file to CSS.
     *
     * @param string $input_file Absolute path to the entry stylesheet
     * @param string $output_file Absolute path the compiled CSS is written to
     * @param bool $is_production Compressed output plus the postcss (autoprefixer + cssnano) pass
     * @param bool $source_maps Embed a base64 sourcemap in the output
     * @return array {bytes, notes}
     */
    public static function compile(
        string $input_file,
        string $output_file,
        bool $is_production,
        bool $source_maps
    ): array {
        // No connect timeout: a compile that queues behind a larger one already in flight is
        // waiting on real work, and every read has always been unbounded.
        $result = Rsx_Node_Service::request('scss.compile', [
            'input_file' => $input_file,
            'output_file' => $output_file,
            'production' => $is_production,
            'source_maps' => $source_maps,
        ]);

        if (!isset($result['result'])) {
            throw new RuntimeException(
                'Invalid response from the SCSS compile RPC: ' . substr(json_encode($result), 0, 500)
            );
        }

        $compile_result = $result['result'];

        if (($compile_result['status'] ?? null) === 'error') {
            $error = $compile_result['error'] ?? [];

            // A failure means the artifact is unusable even if some bytes reached disk: the
            // stylesheet is written BEFORE it is optimized, so a postcss failure leaves
            // un-optimized bytes behind. Remove them, then report sass's own message.
            @unlink($output_file);

            throw new RuntimeException(
                "SCSS compilation failed ({$input_file}):\n" . ($error['message'] ?? 'unknown error')
            );
        }

        if (!file_exists($output_file)) {
            throw new RuntimeException("SCSS compilation produced no output ({$input_file})");
        }

        return [
            'bytes' => (int) ($compile_result['bytes'] ?? 0),
            'notes' => $compile_result['notes'] ?? [],
        ];
    }
}
