<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use Exception;
use App\RSpade\Core\Rsx;

/**
 * Document_Sandbox - THE one place a document binary's command line is built.
 *
 * Every invocation of an untrusted-document binary the framework makes - soffice (the PDF
 * rendition, and the Writer/Calc/Impress text conversions) and pdftotext - is assembled here,
 * so a site has ONE switch that decides whether those binaries run on the host or inside a
 * throwaway container.
 *
 * WHY. soffice and pdftotext are large C++ parsers of hostile input. Their filters are a
 * long-running source of memory-corruption CVEs; a document can carry event or script hooks, can
 * name local files for the filter to read and embed, and can reference resources the converter
 * will fetch over the network. A site that accepts uploads from outside the organisation is
 * handing that parser to strangers, and the host user the render worker runs as can read the
 * application's own tree, its storage and its credentials.
 *
 * THE POSTURE (rsx.libreoffice.sandbox = 'docker'):
 *   one `docker run --rm` PER FILE, sequential, with no network, a read-only root filesystem,
 *   every capability dropped, no-new-privileges, a memory cap, a pid cap, a tmpfs /tmp, and the
 *   worker's own uid:gid. Nothing survives the file: the next document gets a pristine process
 *   in a pristine filesystem. A long-lived per-tenant container would be the wrong trade - it
 *   re-introduces exactly the thing the container is for, a process that one document can leave
 *   modified for the next one, and it buys only container-start latency on a worker that is
 *   already single-threaded and asynchronous.
 *
 * THE WORK DIR IS MOUNTED AT ITS OWN ABSOLUTE PATH (-v <work_dir>:<work_dir>), so every path in
 * the argv - the staged input, the -env:UserInstallation profile dir, the --outdir - is valid
 * unchanged inside the container and the callers build one argv for both modes. NOTHING ELSE is
 * mounted: the blob is COPIED into the work dir by the caller, because mounting the storage tree
 * into the sandbox would defeat the point of having one. The container runs as the worker's own
 * uid:gid so the files it writes into the work dir belong to the worker and are readable and
 * deletable by it.
 *
 * In 'none' mode command() returns the argv untouched and the callers keep passing the host
 * binary path they discovered (Libreoffice::find_soffice() and friends); in 'docker' mode the
 * binary name resolves on the image's PATH, and the fonts are the ones the image installs.
 *
 * NOT COVERED: PhpSpreadsheet. Workbook renditions (Spreadsheet_Rendition) and workbook text
 * (Spreadsheet_Text_Extractor) are read IN-PROCESS by a PHP library - no binary is spawned, so
 * there is no process to contain. Sandboxing them would mean sandboxing PHP itself.
 *
 * @see rsx:man libreoffice (THE DOCUMENT SANDBOX)
 */
class Document_Sandbox
{
    /** Spawn the binary directly on the host. */
    const MODE_NONE = 'none';

    /** Spawn the binary inside a throwaway docker container, one per file. */
    const MODE_DOCKER = 'docker';

    /**
     * The framework's OWN converter image, repository name only - the tag is whatever the
     * configuration names. This is the one image the framework will BUILD; anything else
     * configured here belongs to the operator, who distributes it.
     */
    const FRAMEWORK_IMAGE = 'rspade/rspade-docconvert';

    /** The shipped command that builds it. Named in every error this class throws. */
    const FRAMEWORK_IMAGE_BUILD_COMMAND = 'bash system/app/RSpade/resource/docker/build.sh docconvert';

    /**
     * Whether the configured image is on this host: null until asked, then memoized for the
     * life of the process. A render worker drains many documents in one run and must not pay
     * a `docker image inspect` per document. See ensure_image().
     */
    private static ?bool $_image_present = null;

    /**
     * Whether the daemon accepts the memory cap: null until probed, then memoized for the life
     * of the process (one probe per worker run, not one per document). See memory_cap_applies().
     */
    private static ?bool $_memory_cap_applies = null;

    /** What the daemon said when it refused the memory cap; '' when it accepted it. */
    private static string $_memory_cap_refusal = '';

    /**
     * The configured sandbox mode, validated. An unknown value throws naming the two spellings -
     * a typo here would silently run untrusted documents on the host, which is the one outcome
     * an operator who set this key was trying to avoid.
     *
     * @return string
     * @throws Exception
     */
    public static function mode(): string
    {
        $mode = (string) config('rsx.libreoffice.sandbox', self::MODE_NONE);

        if ($mode !== self::MODE_NONE && $mode !== self::MODE_DOCKER) {
            throw new Exception(
                "rsx.libreoffice.sandbox is '{$mode}' - the only values are '"
                . self::MODE_NONE . "' and '" . self::MODE_DOCKER . "'"
            );
        }

        return $mode;
    }

    /**
     * Is every document binary spawned inside a throwaway container?
     *
     * @return bool
     * @throws Exception
     */
    public static function is_docker(): bool
    {
        return static::mode() === self::MODE_DOCKER;
    }

    /**
     * The image the sandbox runs. It must carry soffice, pdftotext and fonts; the framework's
     * own converter image (FRAMEWORK_IMAGE) carries those three and nothing else, which is why
     * it is the default.
     *
     * @return string
     */
    public static function image(): string
    {
        return (string) config('rsx.libreoffice.sandbox_image');
    }

    /**
     * Is this the framework's own converter image - the one image the framework is entitled to
     * BUILD, at any tag?
     *
     * The tag is ignored on purpose: a site pinning rspade/rspade-docconvert:2026.09 is still
     * naming ours, and build.sh tags every build :latest plus the release revision. An image
     * from anywhere else is the operator's, however it is spelled.
     *
     * @param string $image
     * @return bool
     */
    public static function is_framework_image(string $image): bool
    {
        $repository = $image;

        // Split a trailing :tag off. A registry host may carry a :port, so only a final
        // segment with no '/' after it is a tag.
        $colon = strrpos($image, ':');
        if ($colon !== false && !str_contains(substr($image, $colon + 1), '/')) {
            $repository = substr($image, 0, $colon);
        }

        return $repository === self::FRAMEWORK_IMAGE;
    }

    /**
     * Make sure the configured image exists before anything tries to run it.
     *
     * THE FIRST CONVERSION ON A FRESH BOX MUST NOT FAIL. `LIBREOFFICE_SANDBOX=docker` is a
     * posture, not an installation step, so the framework's own converter image is built the
     * first time it is wanted - one `docker build` from cache, in the render worker, which is
     * asynchronous and already measured in seconds per document. Memoized per process: the
     * worker drains a queue, and the image does not vanish between documents.
     *
     * CALLED BEFORE hardening_flags(), because the memory-cap probe RUNS A CONTAINER on this
     * image to learn whether the daemon admits --memory. Probing before the image exists would
     * record a refusal that is really an absence.
     *
     * AN OPERATOR'S OWN IMAGE IS NEVER BUILT. There is no Dockerfile for it here and no name
     * to guess; an absent one throws naming the image and `docker pull`.
     *
     * @return void
     * @throws Exception
     */
    public static function ensure_image(): void
    {
        if (self::$_image_present === true) {
            return;
        }

        $image = static::image();

        if (static::__probe_image($image)) {
            self::$_image_present = true;

            return;
        }

        if (!static::is_framework_image($image)) {
            throw new Exception(
                "The document sandbox image '{$image}' is not present on this host. It is not the"
                . " framework's own converter image (" . self::FRAMEWORK_IMAGE . "), so the framework"
                . " will not build it: run `docker pull {$image}`, or unset LIBREOFFICE_SANDBOX_IMAGE"
                . ' to use the framework image. See rsx:man libreoffice.'
            );
        }

        static::__build_framework_image($image);

        if (!static::__probe_image($image)) {
            throw new Exception(
                '`' . self::FRAMEWORK_IMAGE_BUILD_COMMAND . "` reported success but '{$image}' is"
                . ' still not present. Run it by hand to see what it tagged.'
            );
        }

        self::$_image_present = true;
    }

    /**
     * Force the image-presence answer without running docker (tests only). null re-arms it.
     */
    public static function _testing_set_image_present(?bool $present): void
    {
        self::$_image_present = $present;
    }

    /**
     * The argv to spawn for a document-binary invocation.
     *
     * 'none'   -> $argv unchanged (the caller passes the host binary path it discovered).
     * 'docker' -> the hardened `docker run --rm` wrapper with $argv appended VERBATIM, the work
     *             dir bind-mounted at its own absolute path so every path inside $argv resolves
     *             identically in the container, and the binary name resolved on the image's PATH.
     *
     * @param array<int, string> $argv The binary and its arguments, as the host would run them.
     * @param string $work_dir Absolute path of this invocation's private work dir (the ONLY path
     *                         the container can see; the staged input lives inside it).
     * @return array<int, string>
     * @throws Exception
     */
    public static function command(array $argv, string $work_dir): array
    {
        if (!static::is_docker()) {
            return $argv;
        }

        // Before hardening_flags(), which probes the daemon by RUNNING this image.
        static::ensure_image();

        return array_merge(
            ['docker', 'run', '--rm', '--name', static::__new_container_name()],
            static::hardening_flags(),
            [
                // The worker's own identity, so what the container writes into the work dir is
                // owned by the process that has to read and delete it.
                '--user', posix_getuid() . ':' . posix_getgid(),
                // soffice writes into HOME before it reads -env:UserInstallation; point it at the
                // one writable place there is.
                '-e', 'HOME=' . $work_dir,
                '-v', $work_dir . ':' . $work_dir,
                // The framework image's own ENTRYPOINT brings a whole environment up; an empty
                // entrypoint clears it so the argv below IS the process, unchanged.
                '--entrypoint', '',
                static::image(),
            ],
            $argv
        );
    }

    /**
     * The containment flags, shared by command() and by the `sandbox run` health probe - the probe
     * proves THESE flags work on THIS box, so it must not carry a softer copy of them.
     *
     * @return array<int, string>
     */
    public static function hardening_flags(): array
    {
        return [
            // A converter that fetches a linked resource is a converter an uploaded document can
            // aim at the internal network.
            '--network', 'none',
            '--read-only',
            '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges',
            // The memory cap is the one flag a daemon can refuse (a host whose cgroup layout is
            // in threaded mode will not enter a memory controller), and a refused cap must not
            // stop documents from converting: it is dropped, once, for the whole process, and
            // the Document Sandbox health rows say so. Everything else is always applied.
            ...(static::memory_cap_applies() ? ['--memory', (string) config('rsx.libreoffice.sandbox_memory')] : []),
            '--pids-limit', (string) config('rsx.libreoffice.sandbox_pids'),
            // The root filesystem is read-only, so give the binary a scratch area that dies with
            // the container.
            '--tmpfs', '/tmp',
        ];
    }

    /**
     * The `--name` a command() result carries, or null for an unsandboxed argv.
     *
     * @param array<int, string> $command
     * @return string|null
     */
    /**
     * Does the daemon accept the configured memory cap?
     *
     * Probed once per process with a container that runs `true` under the cap and nothing
     * else, then memoized: a render worker drains many documents in one run and must not pay a
     * container start per document to learn a fact about the host. A refusal is recorded with
     * the daemon's own words (memory_cap_refusal()) so the health row can repeat them.
     *
     * A refusal drops the cap; it never stops the sandbox. Every other hardening flag is still
     * applied, and the pid cap still holds.
     *
     * @return bool
     */
    public static function memory_cap_applies(): bool
    {
        if (self::$_memory_cap_applies !== null) {
            return self::$_memory_cap_applies;
        }

        $output = [];
        $exit_code = 0;
        exec_safe(
            'docker run --rm --network none --entrypoint ' . escapeshellarg('')
            . ' --memory ' . escapeshellarg((string) config('rsx.libreoffice.sandbox_memory'))
            . ' ' . escapeshellarg(static::image()) . ' true 2>&1',
            $output,
            $exit_code
        );

        self::$_memory_cap_applies = ($exit_code === 0);
        self::$_memory_cap_refusal = $exit_code === 0 ? '' : trim(implode(' ', $output));

        return self::$_memory_cap_applies;
    }

    /**
     * What the daemon said when it refused the memory cap; '' when it accepted it or was not
     * asked yet.
     */
    public static function memory_cap_refusal(): string
    {
        return self::$_memory_cap_refusal;
    }

    /**
     * Force the probe's answer without running docker (tests only). null re-arms the probe.
     */
    public static function _testing_set_memory_cap(?bool $applies, string $refusal = ''): void
    {
        self::$_memory_cap_applies = $applies;
        self::$_memory_cap_refusal = $refusal;
    }

    public static function container_name(array $command): ?string
    {
        if (($command[0] ?? null) !== 'docker') {
            return null;
        }

        $index = array_search('--name', $command, true);
        if ($index === false) {
            return null;
        }

        $name = $command[$index + 1] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * Kill a sandbox container by name, ignoring errors.
     *
     * THE TIMEOUT PATH NEEDS THIS. When the sanctioned bound (rsx.libreoffice.timeout) expires,
     * Symfony kills the process it started - which under the sandbox is the docker CLIENT, not
     * the container. Killing the client leaves the wedged converter running with the work dir
     * still mounted. So every caller that enforces the bound kills the named container beside it.
     *
     * Errors are ignored on purpose: the container may already be gone, which is the outcome we
     * wanted anyway.
     *
     * @param string $name
     * @return void
     */
    public static function kill(string $name): void
    {
        $output = [];
        $exit_code = 0;
        exec_safe('docker kill ' . escapeshellarg($name) . ' > /dev/null 2>&1', $output, $exit_code);
    }

    // =========================================================================
    // health
    // =========================================================================

    /**
     * rsx:health probe: is the document sandbox the posture this box should have, and - when it
     * is on - does the whole chain actually work?
     *
     * A public static `#[Health_Check('label')]` (bare marker attribute - never a defined class)
     * returning a row per Health_Check_Runner's contract, or a LIST of rows. In docker mode it
     * reports five rows, because five independent things can be wrong and an operator needs to
     * know WHICH: the client, the daemon, the image, and one real hardened run (plus the memory
     * cap, which is advisory). A failing link is a FAIL - rsx:health's exit code gates deploys,
     * and that is the point of wiring this to it. THE ONE EXCEPTION is an absent FRAMEWORK
     * converter image, which is a WARN because the render worker builds it on first use; see
     * _image_row().
     *
     * @return array
     */
    #[Health_Check('Document Sandbox')]
    public static function sandbox_health(): array
    {
        $mode = static::mode();

        if ($mode !== self::MODE_DOCKER) {
            return static::_posture_row(Rsx::get_mode());
        }

        $image = static::image();

        $client = static::__probe_client();
        $daemon = $client ? static::__probe_daemon() : null;
        $image_present = $daemon ? static::__probe_image($image) : null;
        $memory_cap = $image_present ? static::memory_cap_applies() : null;
        $run_error = '';
        $run = $image_present ? static::__probe_run($image, $run_error) : null;

        return static::_docker_rows(
            $image,
            $client,
            $daemon,
            $image_present,
            $run,
            $run_error,
            $memory_cap,
            static::memory_cap_refusal()
        );
    }

    /**
     * The row for a box that spawns document binaries on the host.
     *
     * A sealed box is a deployed site, so "no containment" there is something an operator is
     * told about (WARN, advisory - a site whose uploads all come from inside the organisation is
     * entitled to this posture, and rsx:health's exit code must not refuse to deploy it). A
     * development box gets an INFO: it is the default, and a row nobody acts on devalues the rows
     * beside it.
     *
     * @param string $mode The application mode this box is in.
     * @return array{status: string, detail: string, remediation: ?string}
     */
    public static function _posture_row(string $mode): array
    {
        if ($mode === Rsx::MODE_DEVELOPMENT) {
            return [
                'status' => 'INFO',
                'detail' => 'none - soffice and pdftotext run on the host',
                'remediation' => null,
            ];
        }

        return [
            'status' => 'WARN',
            'detail' => 'documents are converted on the host by soffice/pdftotext with no containment',
            'remediation' => 'set LIBREOFFICE_SANDBOX=docker (rsx:man libreoffice)',
        ];
    }

    /**
     * The four docker rows, built from probe results rather than from the probes themselves -
     * a failing docker daemon is not a state this box can be put into, so the branches would
     * otherwise never be seen.
     *
     * A null probe result means "not reached, because an earlier link in the chain failed". It is
     * still a FAIL row: the chain is not proven, and a row that read OK because it was skipped
     * would be a lie.
     *
     * @param string $image The configured sandbox image.
     * @param bool|null $client   docker on PATH.
     * @param bool|null $daemon   `docker info` exit 0.
     * @param bool|null $image_present `docker image inspect <image>` exit 0.
     * @param bool|null $run      a hardened `docker run ... soffice --version` exit 0 with output.
     * @param string $run_error What the daemon or the binary said when that run failed.
     * @return array<int, array{label: string, status: string, detail: string, remediation: ?string}>
     */
    public static function _docker_rows(
        string $image,
        ?bool $client,
        ?bool $daemon,
        ?bool $image_present,
        ?bool $run,
        string $run_error = '',
        ?bool $memory_cap = null,
        string $memory_cap_refusal = ''
    ): array {
        $not_reached = 'not probed - an earlier link in the chain failed';

        // The memory cap is the one hardening flag that is allowed to be absent: a daemon that
        // refuses it (a threaded cgroup layout) still runs every conversion, without the cap,
        // and this row is how an operator learns that it did. WARN, never FAIL - the sandbox
        // is up; one of its five limits is not.
        if ($memory_cap === null) {
            $memory_row = ['label' => 'Document Sandbox: memory cap', 'status' => 'INFO', 'detail' => $not_reached, 'remediation' => null];
        } elseif ($memory_cap) {
            $memory_row = ['label' => 'Document Sandbox: memory cap', 'status' => 'OK', 'detail' => 'the daemon applies --memory ' . config('rsx.libreoffice.sandbox_memory'), 'remediation' => null];
        } else {
            $memory_row = [
                'label' => 'Document Sandbox: memory cap',
                'status' => 'WARN',
                'detail' => 'the daemon refuses a memory cap; sandboxed conversions run WITHOUT one'
                    . ($memory_cap_refusal === '' ? '' : ' (' . $memory_cap_refusal . ')'),
                'remediation' => 'give the docker host a cgroup layout that admits a memory controller'
                    . ' (a nested daemon in a threaded cgroup cannot); the pid cap and every other'
                    . ' hardening flag still apply (rsx:man libreoffice)',
            ];
        }

        return [
            static::__chain_row(
                'Document Sandbox: docker client',
                $client,
                'docker is on the PATH of the user the render worker runs as',
                'docker is not on the PATH of the user the render worker runs as',
                'install the docker client, or set LIBREOFFICE_SANDBOX=none (rsx:man libreoffice)',
                $not_reached
            ),
            static::__chain_row(
                'Document Sandbox: docker daemon',
                $daemon,
                'docker info succeeds',
                'docker info fails - the worker cannot reach a docker daemon',
                'give the worker user access to the docker socket, or run rootless docker'
                . ' (rsx:man libreoffice)',
                $not_reached
            ),
            static::_image_row($image, $image_present, $not_reached),
            // An unbuilt FRAMEWORK image is a WARN on the image row (the worker builds it on
            // first use), so the run it could not attempt is the same WARN, not a FAIL: the
            // exit code must agree with the image row about what this state is.
            ($image_present === false && static::is_framework_image($image))
                ? [
                    'label' => 'Document Sandbox: sandbox run',
                    'status' => 'WARN',
                    'detail' => 'not probed - the converter image is built on first use',
                    'remediation' => 'php artisan rsx:heal document-sandbox-image builds it now',
                ]
                : static::__chain_row(
                    'Document Sandbox: sandbox run',
                    $run,
                    'a hardened container ran soffice --version',
                    'a hardened container could not run soffice --version'
                    . ($run_error === '' ? '' : ': ' . $run_error),
                    'run the printed command by hand to see what the daemon refuses;'
                    . ' the image must carry soffice and pdftotext (rsx:man libreoffice)',
                    $not_reached
                ),
            $memory_row,
        ];
    }

    /**
     * The sandbox-image row, and the one row whose SEVERITY depends on whose image it is.
     *
     * The framework's own converter image is absent on a box that has simply never converted
     * anything yet: the render worker builds it the first time it is wanted, so reporting it
     * as a FAIL would gate a deploy on a state that resolves itself. WARN, naming the build and
     * the heal target for an operator who would rather not pay that cost on the first document.
     *
     * An operator's own image is a different question with a different answer: nothing here can
     * produce it, so an absent one is a FAIL naming `docker pull`.
     *
     * @return array{label: string, status: string, detail: string, remediation: ?string}
     */
    public static function _image_row(string $image, ?bool $image_present, string $not_reached): array
    {
        $label = 'Document Sandbox: sandbox image';

        if ($image_present === true) {
            return ['label' => $label, 'status' => 'OK', 'detail' => $image . ' is present', 'remediation' => null];
        }

        if ($image_present === null) {
            return [
                'label' => $label,
                'status' => 'FAIL',
                'detail' => $not_reached,
                'remediation' => 'docker pull ' . $image . ' (rsx:man libreoffice)',
            ];
        }

        if (static::is_framework_image($image)) {
            return [
                'label' => $label,
                'status' => 'WARN',
                'detail' => $image . ' is not present; it is built on demand, so the first document'
                    . ' conversion on this box will take a minute or two longer while the image builds',
                'remediation' => 'build it once now: php artisan rsx:heal document-sandbox-image',
            ];
        }

        return [
            'label' => $label,
            'status' => 'FAIL',
            'detail' => $image . ' is not present on this host, and it is not the framework image'
                . ' (' . self::FRAMEWORK_IMAGE . '), so nothing here will build it',
            'remediation' => 'docker pull ' . $image . ', or unset LIBREOFFICE_SANDBOX_IMAGE to use'
                . ' the framework converter image (rsx:man libreoffice)',
        ];
    }

    /**
     * rsx:heal document-sandbox-image: build the framework's converter image now, rather than
     * paying for it inside the first conversion. Always a rebuild - a present image is rebuilt
     * from cache in seconds, which keeps the tagged image in step with this checkout.
     *
     * Creation-only, per the Heal_Runner boundary: an image that is already present is never
     * rebuilt, and an image that is not ours is REFUSED - the framework has no Dockerfile for
     * it and would be guessing at what the operator meant.
     *
     * @return array{status: string, detail: string}
     */
    #[Health_Heal('document-sandbox-image')]
    public static function heal_sandbox_image(): array
    {
        $image = static::image();

        if (!static::is_framework_image($image)) {
            return [
                'status' => 'REFUSED',
                'detail' => "{$image} is not the framework's converter image (" . self::FRAMEWORK_IMAGE
                    . "), so there is nothing here to build it from. Run `docker pull {$image}`, or"
                    . ' unset LIBREOFFICE_SANDBOX_IMAGE to use the framework image.',
            ];
        }

        // ALWAYS a rebuild, present or not: docker's layer cache makes a rebuild of an
        // unchanged image a matter of seconds, and an operator who asks for the image wants
        // the image this checkout describes, not whatever was tagged before.
        $was_present = static::__probe_image($image);
        static::__build_framework_image($image);
        self::$_image_present = null;

        if (!static::__probe_image($image)) {
            return [
                'status' => 'REFUSED',
                'detail' => '`' . self::FRAMEWORK_IMAGE_BUILD_COMMAND . "` reported success but {$image}"
                    . ' is still not present. Run it by hand to see what it tagged.',
            ];
        }

        return [
            'status' => 'HEALED',
            'detail' => ($was_present ? 'Rebuilt ' : 'Built ') . $image . ' (' . self::FRAMEWORK_IMAGE_BUILD_COMMAND . ').',
        ];
    }

    /**
     * Run the framework's own converter-image build. Docker caches every layer, so a rebuild
     * of an image that is already present costs seconds; a first build costs minutes.
     *
     * @throws Exception when the build exits non-zero, carrying its last lines
     */
    private static function __build_framework_image(string $image): void
    {
        $script = base_path('app/RSpade/resource/docker/build.sh');

        $output = [];
        $exit_code = 0;
        // NO TIMEOUT (framework mandate): an image build takes as long as it takes.
        exec_safe('bash ' . escapeshellarg($script) . ' docconvert 2>&1', $output, $exit_code);

        if ($exit_code !== 0) {
            $tail = implode("\n", array_slice($output, -20));

            throw new Exception(
                "`" . self::FRAMEWORK_IMAGE_BUILD_COMMAND . "` failed to build the document sandbox"
                . " image '{$image}' (exit " . $exit_code . "):\n" . $tail
            );
        }
    }

    // =========================================================================
    // internals
    // =========================================================================

    /**
     * One chain row: OK, FAIL, or FAIL-because-not-reached.
     *
     * @return array{label: string, status: string, detail: string, remediation: ?string}
     */
    private static function __chain_row(
        string $label,
        ?bool $ok,
        string $ok_detail,
        string $fail_detail,
        string $remediation,
        string $not_reached
    ): array {
        if ($ok === true) {
            return ['label' => $label, 'status' => 'OK', 'detail' => $ok_detail, 'remediation' => null];
        }

        return [
            'label' => $label,
            'status' => 'FAIL',
            'detail' => $ok === null ? $not_reached : $fail_detail,
            'remediation' => $remediation,
        ];
    }

    /**
     * An unguessable per-invocation container name, so the timeout path has something to kill and
     * two concurrent workers never collide.
     *
     * @return string
     */
    private static function __new_container_name(): string
    {
        return 'rsx-doc-' . bin2hex(random_bytes(8));
    }

    /**
     * @return bool
     */
    private static function __probe_client(): bool
    {
        $found = trim((string) shell_exec('bash -c ' . escapeshellarg('command -v docker 2>/dev/null')));

        return $found !== '' && is_executable($found);
    }

    /**
     * @return bool
     */
    private static function __probe_daemon(): bool
    {
        $output = [];
        $exit_code = 0;
        exec_safe('docker info > /dev/null 2>&1', $output, $exit_code);

        return $exit_code === 0;
    }

    /**
     * @return bool
     */
    private static function __probe_image(string $image): bool
    {
        $output = [];
        $exit_code = 0;
        exec_safe('docker image inspect ' . escapeshellarg($image) . ' > /dev/null 2>&1', $output, $exit_code);

        return $exit_code === 0;
    }

    /**
     * THE row that proves the whole chain: the same hardening flags a real conversion gets, the
     * configured image, and soffice answering from inside it. No work dir is mounted - this run
     * converts nothing.
     *
     * @param string $error Receives what the daemon or the binary said on failure - a refused
     *                       hardening flag is the operator's whole diagnosis.
     * @return bool
     */
    private static function __probe_run(string $image, string &$error): bool
    {
        $flags = '';
        foreach (static::hardening_flags() as $flag) {
            $flags .= ' ' . escapeshellarg($flag);
        }

        $output = [];
        $exit_code = 0;
        exec_safe(
            'docker run --rm' . $flags . ' --entrypoint ' . escapeshellarg('')
            . ' ' . escapeshellarg($image) . ' soffice --version 2>&1',
            $output,
            $exit_code
        );

        $text = trim(implode(' ', $output));

        if ($exit_code !== 0 || $text === '') {
            $error = $text;

            return false;
        }

        return true;
    }
}
