<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Locks;

use Symfony\Component\Console\Command\Command as Symfony_Command;
use RuntimeException;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use App\RSpade\Core\Locks\Site_Lock;

/**
 * Site_Lock_Cli - the site lock ENFORCEMENT WINDOW of command-line code: every artisan
 * command, and every script booted through system/script.php.
 *
 * An operator command is enforced like a task body: NO automatic lock, but inside an
 * enforcement window, so a save() / delete() / bulk write of a Site_Scoped model there
 * fatals unless the command holds that site's write lock - a
 * Site_Lock::write($site_id, fn () => ...) section per site it writes, re-reading what it
 * changes inside - or has opened a Site_Lock::bypass() / bypass_all_sites() with a reason.
 * The window is not a request window: the command may change the site
 * (Session::set_site_id(), Rsx_Cli_Actor::establish()), between its sections, never
 * under a lock or bypass.
 *
 * ARTISAN. app/Console/Kernel.php adds _listen()'s two listeners to the Symfony event
 * dispatcher every console Application runs commands through: the window opens on
 * ConsoleEvents::COMMAND (right before the command's run() - after input binding, before
 * handle()) and closes on ConsoleEvents::TERMINATE, which Symfony dispatches whether the
 * command returned or threw. That covers a command started from the shell, an
 * Artisan::call() from anywhere (a nested window inside a request or task window), and the
 * #[Command] task aliases (whose task body then opens its own task window inside this one).
 * A command a command runs with $this->call() goes through Command::run() directly and
 * raises no event: it runs in its caller's window. The four pre-boot commands
 * (rsx:framework:pull, rsx:maintenance:enable / disable, rsx:git) never reach PHP.
 *
 * SCRIPTS. system/script.php opens a window right after the boot (_open_script_window())
 * and leaves it open for the life of the process: everything the script runs, its
 * shutdown functions included, is command-line code.
 *
 * EXEMPT COMMANDS. A command whose job is to provision or replace the database wholesale
 * runs outside every window, unchecked, by an explicit, reasoned declaration on its class:
 *
 *     class Maint_Migrate extends Command
 *     {
 *         public const SITE_LOCK_EXEMPT = 'schema migrations: raw SQL, outside every window';
 *
 * A class constant, read without reflection (command classes are not manifest-indexed); the
 * reason is required, and the
 * declaration is inherited by a subclass. Vendor commands that cannot carry it are
 * declared once, here, by the vendor BASE CLASS of their family (VENDOR_EXEMPT_FAMILIES) -
 * never by command name. An exempt command opens no window of its own; called from inside
 * another window it runs in that one.
 *
 * Exemption is for provisioning only - migrations, seeders, the test runner, the database
 * reset and the provisioning-cache snapshot. Never for a command that edits tenant data:
 * that command takes Site_Lock::write() sections like any other code.
 *
 * See: php artisan rsx:man site_locks
 */
class Site_Lock_Cli
{
    /**
     * The class constant an exempt command declares: const SITE_LOCK_EXEMPT = '<reason>';
     * A constant, not an attribute: command classes are not manifest-indexed, and a constant is
     * inherited and read without reflection.
     */
    public const EXEMPT_CONSTANT = 'SITE_LOCK_EXEMPT';

    /**
     * Laravel's own command families that are exempt, by base class, with the reason. The
     * one place a vendor exemption is declared: their classes cannot carry the attribute.
     */
    public const VENDOR_EXEMPT_FAMILIES = [
        // migrate:install, migrate:rollback and every other Laravel migration command (the
        // RSpade overrides - migrate, migrate:status ... - declare their own).
        \Illuminate\Database\Console\Migrations\BaseCommand::class => 'Laravel migration commands: schema migrations are outside every window',
        // db:seed (RSpade's Seed_Command extends it and declares its own reason).
        \Illuminate\Database\Console\Seeds\SeedCommand::class => 'Laravel seeders: database provisioning, outside every window',
    ];

    /**
     * The command invocations in progress, innermost last: [command, label | null (exempt)].
     * Pairs each TERMINATE with its own COMMAND, so a COMMAND listener that threw (no entry
     * pushed) never closes somebody else's window.
     */
    private static array $invocations = [];

    /** class => exemption reason | null */
    private static array $exemption_cache = [];

    /**
     * Add the window listeners to the dispatcher the console Application dispatches its
     * command events through. Called once, by app/Console/Kernel.php.
     */
    public static function _listen(EventDispatcher $dispatcher): void
    {
        // The window spans every other listener on both events.
        $dispatcher->addListener(ConsoleEvents::COMMAND, function (ConsoleCommandEvent $event): void {
            static::_command_starting($event->getCommand());
        }, 1024);

        $dispatcher->addListener(ConsoleEvents::TERMINATE, function (ConsoleTerminateEvent $event): void {
            static::_command_finished($event->getCommand(), $event->getExitCode());
        }, -1024);
    }

    /**
     * A command is about to run: open its enforcement window, unless it is exempt.
     */
    public static function _command_starting(?Symfony_Command $command): void
    {
        if ($command === null) {
            return;
        }

        $label = static::exemption_reason($command) === null ? static::__label($command) : null;

        self::$invocations[] = ['command' => $command, 'label' => $label];

        if ($label !== null) {
            Site_Lock::_enter_enforcement($label);
        }
    }

    /**
     * A command has ended (returned or threw): close the window _command_starting() opened.
     * A write exception the command left open is released; a command that succeeded
     * (exit code 0) then fails naming it, one that already failed keeps its own outcome.
     *
     * @param Symfony_Command|null $command
     * @param int $exit_code The command's exit code (non-zero: it failed or threw)
     */
    public static function _command_finished(?Symfony_Command $command, int $exit_code = 0): void
    {
        if ($command === null || self::$invocations === []) {
            return;
        }

        $top = self::$invocations[array_key_last(self::$invocations)];

        // No entry of its own: its COMMAND listener threw before pushing one.
        if ($top['command'] !== $command) {
            return;
        }

        array_pop(self::$invocations);

        if ($top['label'] !== null) {
            Site_Lock::_leave_enforcement_labelled($top['label'], $exit_code === 0);
        }
    }

    /**
     * Open the enforcement window of a script booted through system/script.php. It stays
     * open for the life of the process.
     *
     * @param string $script The script file, for refusal messages
     */
    public static function _open_script_window(string $script): void
    {
        Site_Lock::_enter_enforcement('script ' . $script);
    }

    /**
     * Why a command is exempt from enforcement, or null when it is enforced: its own (or an
     * ancestor's) SITE_LOCK_EXEMPT constant, else a VENDOR_EXEMPT_FAMILIES
     * base class it descends from.
     *
     * @param Symfony_Command|string $command The command, or its class
     * @return string|null
     */
    public static function exemption_reason(Symfony_Command|string $command): ?string
    {
        $class = is_string($command) ? $command : get_class($command);

        if (array_key_exists($class, self::$exemption_cache)) {
            return self::$exemption_cache[$class];
        }

        $reason = null;
        $constant = $class . '::' . self::EXEMPT_CONSTANT;

        if (defined($constant)) {
            $reason = trim((string) constant($constant));

            if ($reason === '') {
                throw new RuntimeException(
                    "{$class} declares " . self::EXEMPT_CONSTANT . ' with no reason. Exemption is for commands '
                    . 'that provision or replace the database wholesale; say why: '
                    . "const SITE_LOCK_EXEMPT = 'schema migrations: raw SQL, outside every window';"
                );
            }
        }

        if ($reason === null) {
            foreach (self::VENDOR_EXEMPT_FAMILIES as $base => $family_reason) {
                if (is_a($class, $base, true)) {
                    $reason = $family_reason;
                    break;
                }
            }
        }

        return self::$exemption_cache[$class] = $reason;
    }

    /** 'command rsx:users:email:set' */
    private static function __label(Symfony_Command $command): string
    {
        return 'command ' . ($command->getName() ?? get_class($command));
    }
}
