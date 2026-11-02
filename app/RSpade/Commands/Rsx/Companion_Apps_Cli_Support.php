<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Companion\Companion_Daemon_Client;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use Illuminate\Console\Command;
use Symfony\Component\Process\ExecutableFinder;

/**
 * What the six rsx:companion_apps commands share.
 *
 * The order is always FLAGS FIRST, THEN THE SOCKET: the flag files are the state, and the socket
 * only tells a running daemon to look now. So a command is correct with the daemon down, which
 * then reads the flags when it comes back, and it says so plainly. Nothing here needs a database.
 */
trait Companion_Apps_Cli_Support
{
    /**
     * The declared companions, from the build's descriptors: name => descriptor.
     *
     * @return array<string, array>
     */
    protected function declared_companions(): array
    {
        $companions = [];

        foreach (glob(Rsx_Project_Paths::companions_build_dir() . '/*/descriptor.json') ?: [] as $file) {
            $descriptor = json_decode((string) file_get_contents($file), true);

            if (is_array($descriptor) && isset($descriptor['name'])) {
                $companions[$descriptor['name']] = $descriptor;
            }
        }

        ksort($companions);

        return $companions;
    }

    /**
     * The names this invocation acts on: the one named, or every declared companion. Null after
     * printing why there are none.
     *
     * @return array<int, string>|null
     */
    protected function target_names(): ?array
    {
        $declared = $this->declared_companions();
        $name = $this->argument('name');

        if ($name !== null) {
            if (!isset($declared[$name])) {
                $this->error("[ERROR] No companion is declared with the name '{$name}'.");
                $this->line('Declared: ' . ($declared === [] ? '(none)' : implode(', ', array_keys($declared))));

                return null;
            }

            return [$name];
        }

        if ($declared === []) {
            $this->line('No companion applications are declared.');

            return null;
        }

        return array_keys($declared);
    }

    /**
     * Tell a running daemon. Answers its reply, or null after explaining that none runs.
     */
    protected function tell_daemon(string $verb, array $arguments = [], bool $state_recorded = true): ?array
    {
        $answer = Companion_Daemon_Client::send($verb, $arguments);

        if ($answer === null) {
            $this->explain_daemon_absent($state_recorded);
        }

        return $answer;
    }

    protected function explain_daemon_absent(bool $state_recorded = true): void
    {
        $this->line($state_recorded
            ? 'rsx-companiond is not running. The desired state is recorded and applies when it starts.'
            : 'rsx-companiond is not running, so nothing was restarted. A companion desired running starts when it does.');

        if ((new ExecutableFinder())->find('supervisorctl') === null) {
            $this->line('This box has no supervisord, which is what keeps rsx-companiond running: companions need it.');
        }
    }
}
