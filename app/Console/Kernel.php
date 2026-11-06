<?php

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

/**
 * Laravel's scheduler is NOT the framework's scheduling story - there is no
 * schedule() override here on purpose. Recurring work is declared with
 * #[Schedule] on a #[Task] method and driven by the rsx:task:process cron.
 */
class Kernel extends ConsoleKernel
{
    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');           // Application commands
        $this->load(__DIR__.'/../RSpade/Commands'); // Framework commands

        // One artisan command per #[Command]-annotated #[Task]. An application declares a
        // command by annotating the task, never by writing a class - see
        // rsx:man task_commands.
        \App\RSpade\Core\Task\Task_Command_Registrar::register();

        require base_path('routes/console.php');
    }

    /**
     * Laravel's re-routing of the Symfony command events, plus the site lock ENFORCEMENT
     * WINDOW every command runs in (Site_Lock_Cli): opened on ConsoleEvents::COMMAND, closed
     * on ConsoleEvents::TERMINATE, for a command run from the shell and for Artisan::call()
     * alike. A command declaring const SITE_LOCK_EXEMPT = '<reason>' runs unchecked.
     *
     * @return $this
     */
    public function rerouteSymfonyCommandEvents()
    {
        $first = $this->symfonyDispatcher === null;

        parent::rerouteSymfonyCommandEvents();

        if ($first) {
            \App\RSpade\Core\Locks\Site_Lock_Cli::_listen($this->symfonyDispatcher);
        }

        // Laravel hands the dispatcher to the console Application only when it CREATES it
        // (getArtisan()). Something that resolved Artisan during the boot - before this
        // booted() callback ran - left it without one, and no command it runs would raise an
        // event: no window. Attach it here, before the first command runs.
        if ($this->artisan !== null) {
            $this->artisan->setDispatcher($this->symfonyDispatcher);
        }

        return $this;
    }
}
