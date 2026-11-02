<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Companion;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Service\Rsx_Service_Abstract;

/**
 * Companion_Service_Abstract - declares one companion application.
 *
 * A companion is a long-running process that is part of the application's own code tree: a
 * watcher, a socket listener, a queue consumer, a protocol bridge. rsx-companiond starts and
 * stops it, and it calls the application through #[Companion_Endpoint] methods. One subclass
 * per companion, beside its own source:
 *
 *     rsx/companions/<name>/<name>_companion_service.php     class X_Companion_Service extends Companion_Service_Abstract
 *     rsx/companions/<name>/resource/main.js         the process
 *
 * Node source lives under resource/ because the manifest does not scan that directory.
 *
 * THE DECLARATION IS READ AT BUILD, BY LOADING THE CLASS, AND MUST BE A PURE FUNCTION OF THE
 * CODE: a build issues no query and its output is shared between boxes, so these methods touch
 * no database and read no environment. ready_to_run() is the one method that runs live.
 * The manifest writes what they answer to build/companions/<name>/descriptor.json, which is
 * all the daemon ever reads, so a companion starts with no PHP running.
 *
 * ENDPOINTS ARE NOT LISTED HERE. They are #[Companion_Endpoint('<name>')] methods, on any class
 * with no class-level #[Auth]; endpoints() answers what the manifest collected. #[Task] and
 * #[Schedule] methods are ordinary tasks and work on a subclass.
 */
abstract class Companion_Service_Abstract extends Rsx_Service_Abstract
{
    /** A companion or endpoint name. A leading underscore is impossible: /_companion/_daemon. */
    public const NAME_PATTERN = '[a-z][a-z0-9_]*';

    public const RESTART_ALWAYS = 'always';
    public const RESTART_ON_FAILURE = 'on_failure';
    public const RESTART_NEVER = 'never';

    public const CODE_CHANGE_IGNORE = 'ignore';
    public const CODE_CHANGE_PAUSE = 'pause';
    public const CODE_CHANGE_RESTART_IF_AFFECTED = 'restart_if_affected';
    public const CODE_CHANGE_RESTART = 'restart';

    /**
     * The endpoint name the Node SDK's ready() posts to. It is never declared: the verifier
     * checks signature, liveness and contract before it asks whether an endpoint exists, so the
     * 404 this answers proves all three. The manifest refuses an endpoint by this name, and the
     * request log counts its 404 as the success it is.
     */
    public const SDK_PROBE_ENDPOINT = 'rsx_sdk_contract_probe';

    /**
     * The largest shutdown_grace_seconds() a companion may declare. The container supervisor
     * must wait out rsx-companiond's own stop, so its stopwaitsecs is fixed above this number;
     * a grace past it would be cut short by SIGKILL with no warning, so the build refuses it.
     */
    public const MAX_SHUTDOWN_GRACE_SECONDS = 300;

    /**
     * Stable machine name: the URL segment, the log name and the actor's name. Lower case
     * letters, digits and underscores, starting with a letter.
     */
    abstract public static function name(): string;

    /**
     * One line, shown in /_sys and the list command.
     */
    abstract public static function description(): string;

    /**
     * The argv that starts it, never through a shell: ['node', 'resource/main.js']. Relative
     * paths resolve from working_directory().
     *
     * @return array<int, string>
     */
    abstract public static function command(): array;

    /**
     * Where it runs, relative to the companion's own directory and inside it.
     */
    #[Replaceable]
    public static function working_directory(): string
    {
        return '.';
    }

    /**
     * Extra environment for the process. These are BUILD OUTPUT, written into the descriptor:
     * fixed values, never a secret. A companion fetches a secret through an endpoint.
     *
     * @return array<string, string>
     */
    #[Replaceable]
    public static function environment(): array
    {
        return [];
    }

    /**
     * How long a graceful stop waits after SIGTERM before SIGKILL. The companion's contract is
     * to finish its unit of work, persist its checkpoint and exit 0; kill safety comes from
     * idempotent calls, never from this number. At most MAX_SHUTDOWN_GRACE_SECONDS.
     */
    #[Replaceable]
    public static function shutdown_grace_seconds(): int
    {
        return 30;
    }

    /**
     * RESTART_ALWAYS, RESTART_ON_FAILURE or RESTART_NEVER.
     */
    #[Replaceable]
    public static function restart_policy(): string
    {
        return self::RESTART_ALWAYS;
    }

    /**
     * The first restart delay after a crash; it doubles per consecutive crash.
     */
    #[Replaceable]
    public static function restart_backoff_initial_seconds(): int
    {
        return 1;
    }

    /**
     * The ceiling the restart delay doubles up to.
     */
    #[Replaceable]
    public static function restart_backoff_ceiling_seconds(): int
    {
        return 300;
    }

    /**
     * One running copy per installation, elected through rsx-lockd. False runs one per host.
     */
    #[Replaceable]
    public static function single_instance(): bool
    {
        return true;
    }

    /**
     * What a running companion does when development code changes: CODE_CHANGE_IGNORE,
     * CODE_CHANGE_PAUSE, CODE_CHANGE_RESTART_IF_AFFECTED or CODE_CHANGE_RESTART. Production
     * ignores it: code arrives there through a deploy.
     */
    #[Replaceable]
    public static function on_code_change(): string
    {
        return self::CODE_CHANGE_RESTART_IF_AFFECTED;
    }

    /**
     * Why this companion should not run right now, or null when it may. Asked by the daemon
     * before each start and on each backoff tick; while it answers a reason the companion
     * shows as WAITING, and no restart is counted. A throw counts as a reason.
     *
     * READ-ONLY, which the build checks of this method's own body. It has no request site: a
     * single tenant install declares site 1, and on a multi tenant install a site-scoped read
     * here throws, so it reads with without_site_scope().
     */
    #[Replaceable]
    public static function ready_to_run(): ?string
    {
        return null;
    }

    /**
     * The endpoints the manifest collected for this companion, sorted.
     *
     * @return array<int, string>
     */
    final public static function endpoints(): array
    {
        return array_keys(Manifest::get_companions()[static::name()]['endpoints'] ?? []);
    }
}
