<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use LogicException;
use Symfony\Component\Process\Process;
use App\RSpade\Core\Files\Document_Sandbox;
use App\RSpade\Core\Files\Document_Stage_Timeout_Exception;

/**
 * The wall-clock bound on one stage of the document worker.
 *
 * A stage is one parse of one uploaded file: the render (a rendition from soffice, or from
 * PhpSpreadsheet in-process) or the text extraction. Each gets rsx.documents.stage_seconds
 * - a SANCTIONED TIMEOUT, justified in full at that config key - and a stage that runs past
 * it is abandoned with a Document_Stage_Timeout_Exception, which its caller records as a
 * FAILED attempt like any other failure.
 *
 * THE MECHANISM is an alarm: pcntl_alarm() delivers SIGALRM after the limit, async signal
 * dispatch runs the handler between PHP statements, and the handler throws. That reaches
 * PHP code wherever it is (a spreadsheet reader looping over a damaged workbook) and the
 * wait loop of a spawned binary alike. It does NOT reach a process stuck inside a single
 * native call - the handler only runs once that call returns - which is why the parent
 * also holds a hard kill over the whole child (rsx.documents.kill_seconds).
 *
 * CLI ONLY. pcntl does not exist under php-fpm, so a stage runs in a command-line process
 * or not at all: the worker's child (rsx:documents:process_one), an operator command, a
 * test. A web request that needs a document processed asks
 * Document_Render_Service::process_storage(), which spawns that child.
 */
class Document_Stage_Bound
{
    /** The stage currently bounded in this process, or null. A stage never nests. */
    private static ?string $active_stage = null;

    /**
     * Refuse loudly outside the command line, before any state is touched.
     *
     * @param string $what The caller, for the message.
     * @return void
     */
    public static function require_cli(string $what): void
    {
        if (\PHP_SAPI !== 'cli') {
            throw new LogicException(
                "{$what} runs only in a command-line process: its stages are bounded by a pcntl alarm, "
                . 'which does not exist under ' . \PHP_SAPI . '. From a web request call '
                . 'Document_Render_Service::process_storage(), which runs the document in a child process.'
            );
        }
    }

    /**
     * Run one stage under the bound and return what it returns.
     *
     * @param string $stage What the stage is, as the failure reason names it ('render',
     *                      'text extraction').
     * @param callable $work
     * @return mixed
     * @throws Document_Stage_Timeout_Exception when the stage ran past the limit.
     */
    public static function run(string $stage, callable $work)
    {
        static::require_cli("The document {$stage} stage");

        if (self::$active_stage !== null) {
            shouldnt_happen("Document stage '{$stage}' started inside stage '" . self::$active_stage . "' - a process has one alarm");
        }

        $seconds = static::seconds();
        $was_async = pcntl_async_signals(true);

        pcntl_signal(SIGALRM, function () use ($stage, $seconds) {
            throw new Document_Stage_Timeout_Exception("{$stage} exceeded {$seconds} seconds");
        });

        self::$active_stage = $stage;
        pcntl_alarm($seconds);

        try {
            return $work();
        } finally {
            // Disarm the handler FIRST: an alarm delivered while the stage was returning
            // must not throw out of this block.
            pcntl_signal(SIGALRM, SIG_IGN);
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_async_signals($was_async);
            self::$active_stage = null;
        }
    }

    /**
     * Run an external document binary (soffice, pdftotext - possibly inside the document
     * sandbox) to completion INSIDE the current stage, and return the finished process.
     *
     * The process carries no limit of its own: the stage it runs in is the limit, one
     * number for the in-process readers and the binaries alike. Called outside a stage it
     * refuses, because that would be a converter of hostile input with nothing bounding it.
     *
     * When the stage expires mid-run the process is stopped before the exception continues
     * - and under the sandbox the process is only the docker CLIENT, so the container it
     * was attached to is killed by name as well, or the wedged converter would run on with
     * the work directory still mounted.
     *
     * @param array<int, string> $command The argv, as Document_Sandbox::command() built it.
     * @return Process The process, already finished.
     */
    public static function run_process(array $command): Process
    {
        if (self::$active_stage === null) {
            shouldnt_happen('A document binary was started outside a bounded stage: ' . ($command[0] ?? ''));
        }

        $process = new Process($command);

        // No per-process limit: the stage alarm is the one bound (see the class docblock).
        $process->setTimeout(null);

        try {
            $process->run();
        } catch (Document_Stage_Timeout_Exception $e) {
            $process->stop(0);

            $container = Document_Sandbox::container_name($command);
            if ($container !== null) {
                Document_Sandbox::kill($container);
            }

            throw $e;
        }

        return $process;
    }

    /**
     * The stage limit in seconds (rsx.documents.stage_seconds).
     *
     * @return int
     */
    public static function seconds(): int
    {
        $seconds = (int) config('rsx.documents.stage_seconds');

        if ($seconds < 1) {
            shouldnt_happen('rsx.documents.stage_seconds must be a positive number of seconds');
        }

        return $seconds;
    }
}
