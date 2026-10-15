#!/bin/bash
#
# prod_probe_overrides.sh - a REAL class override for the production-mode lifecycle, and the
# measurements that prove a sealed build serves it without touching system/. Sourced, never
# executed.
#
# An application overrides a framework PHP class by declaring the same simple name under rsx/
# (rsx:man class_override); only PHP classes are overridable. The framework file stays where
# it is, byte for byte: the build records it as SHADOWED, the class override loader answers
# its FQCN with the application's class, and every bundle that reaches the framework file
# compiles the override in its place. A sealed production box is where that has to hold
# hardest - system/ is deployed read-only, and composer's autoloader is classmap-authoritative
# there, so a class only resolves to the override because the override loader stands in front
# of composer.
#
# THE PROBE, written here and removed again:
#
#   rsx/models/region_model.php   a PHP override of the framework's Region_Model, extending
#                                 its _Abstract base (the core-model override shape) and
#                                 carrying the marker as a public class constant. The model's
#                                 generated JS stub carries every public constant the model
#                                 declares, and Core_Bundle's Core/Models include reaches the
#                                 framework file in EVERY bundle - so the marker is how the
#                                 sealed /login bundle, which names no application model,
#                                 shows it compiled the override (Region_Model.PROBE)
#
#   probe_overrides_create        write the probe (refusing to overwrite a real file)
#   probe_overrides_remove        remove it, and put back any rsx/ file the import fixer
#                                 repointed at it; idempotent, so an EXIT trap can call it
#
# THE REPOINTED IMPORT. An rsx/ file resolves a simple name to the rsx/ class, so while the
# PHP probe exists the development-mode import fixer (Php_Fixer) repoints an application
# file's `use App\RSpade\Core\Models\Region_Model;` at the probe's Rsx\ FQCN - which
# happens on the development boot that runs rsx:prod:enable. Both names answer with the
# same class while the probe exists. A production build runs no fixer, so once the probe is
# gone that import would name a class that no longer exists: removal therefore restores
# every tracked rsx/ file that was unmodified when the probe was written and is modified
# now, and says so in the log.
#
# THE MEASUREMENTS. system_fingerprint prints one line per fact the build must not move:
# the git status of system/app, system/config and system/bin, every *.upstream file under
# system/app, and the sha1 of the shadowed framework file. composer_fingerprint prints
# the sha1 of every file under system/vendor/composer - the one place a strict production
# build legitimately writes (it regenerates the autoloader classmap-authoritative, and
# rsx:prod:disable regenerates it again), compared file by file by the caller.
#
# The caller provides PROJECT_ROOT, SYSTEM_DIR and LOG, has cd'd to PROJECT_ROOT, and has
# sourced prod_probe_migration.sh (for probe_php).

PROBE_OVERRIDE_MARKER="rsx_prod_probe_override_$$"

PROBE_PHP_OVERRIDE_FILE="rsx/models/region_model.php"
PROBE_PHP_SHADOWED_FILE="system/app/RSpade/Core/Models/Region_Model.php"
PROBE_PHP_FRAMEWORK_FQCN='App\RSpade\Core\Models\Region_Model'
PROBE_PHP_OVERRIDE_FQCN='Rsx\Models\Region_Model'

PROBE_OVERRIDES_WRITTEN=false
PROBE_RSX_MODIFIED_BEFORE=""

probe_overrides_create() {
    if [ -e "$PROBE_PHP_OVERRIDE_FILE" ]; then
        echo "$PROBE_PHP_OVERRIDE_FILE already exists - this application overrides Region_Model itself" >> "$LOG"
        return 1
    fi

    PROBE_RSX_MODIFIED_BEFORE="$(git diff --name-only -- rsx)"
    PROBE_OVERRIDES_WRITTEN=true

    cat > "$PROBE_PHP_OVERRIDE_FILE" <<PHP
<?php

namespace Rsx\Models;

use App\RSpade\Core\Models\Region_Model_Abstract;

/**
 * Written by the production-mode lifecycle test and removed by it again: an application
 * override of a framework core model, so that a sealed box has one to resolve and its
 * bundles one to compile.
 */
class Region_Model extends Region_Model_Abstract
{
    const PROBE = '$PROBE_OVERRIDE_MARKER';
}
PHP
}

probe_overrides_remove() {
    [ "$PROBE_OVERRIDES_WRITTEN" = true ] || return 0

    rm -f "$PROBE_PHP_OVERRIDE_FILE"

    local file
    for file in $(git diff --name-only -- rsx); do
        if ! echo "$PROBE_RSX_MODIFIED_BEFORE" | grep -qxF "$file"; then
            echo "[probe overrides] restoring $file (rewritten while the probes existed):" >> "$LOG"
            git diff -- "$file" >> "$LOG"
            git checkout -- "$file"
        fi
    done

    PROBE_OVERRIDES_WRITTEN=false
}

system_fingerprint() {
    echo "## git status -- system/app system/config system/bin"
    git status --porcelain -- system/app system/config system/bin
    echo "## *.upstream under system/app"
    find system/app -name '*.upstream' 2>/dev/null | sort
    echo "## shadowed framework files"
    sha1sum "$PROBE_PHP_SHADOWED_FILE"
}

composer_fingerprint() {
    find system/vendor/composer -type f -print0 | sort -z | xargs -0 sha1sum
}

# What a fresh process answers for the framework FQCN: "<resolved class>|<PROBE>|<A or N per
# registered composer loader, A = classmap-authoritative>".
probe_override_resolution() {
    PROBE_FQCN="$PROBE_PHP_FRAMEWORK_FQCN" probe_php 2>> "$LOG" <<'PHP'
        $fqcn = getenv('PROBE_FQCN');
        $class = new ReflectionClass($fqcn);
        $modes = '';
        foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
            $modes .= $loader->isClassMapAuthoritative() ? 'A' : 'N';
        }
        $probe = $class->getName() . '::PROBE';
        echo $class->getName() . '|' . (defined($probe) ? constant($probe) : '') . '|' . $modes;
PHP
}

# The overrides the box reports, one class name per line, sorted.
#   $1 the JSON rsx:overrides:list --json printed
probe_overrides_listed() {
    echo "$1" | php -r '
        $data = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($data) || !isset($data["overrides"])) {
            fwrite(STDERR, "rsx:overrides:list --json printed no overrides key\n");
            exit(1);
        }
        $out = [];
        foreach ($data["overrides"] as $row) {
            $out[] = $row["name"];
        }
        sort($out);
        echo implode("\n", $out);
    '
}
