#!/bin/bash

# RSpade Test Environment Helpers
#
# Manages test database environment and provides utilities for test setup/teardown

TEST_ENV_BACKUP="/var/www/html/system/.env.testbackup"
TEST_MODE_ACTIVE=false

# Enter test mode - switch to test database
function test_mode_enter() {
    if [ "$TEST_MODE_ACTIVE" = true ]; then
        echo "[ERROR] Test mode already active" >&2
        return 1
    fi

    echo "[TEST ENV] Entering test mode..." >&2

    # Check if .env is stuck in test mode from a previous interrupted run
    if grep -q "^DB_DATABASE=rspade_test" /var/www/html/.env; then
        if [ -f "$TEST_ENV_BACKUP" ]; then
            echo "[TEST ENV] Detected unclean shutdown - restoring from backup..." >&2
            cp "$TEST_ENV_BACKUP" /var/www/html/.env
            # Skip config:clear during recovery - just restore the file
            echo "[TEST ENV] Recovery complete" >&2
        else
            echo "[TEST ENV] ERROR: .env stuck in test mode but no backup exists" >&2
            echo "[TEST ENV] Manually restore /var/www/html/.env to production credentials" >&2
            return 1
        fi
    fi

    # Backup current .env (production credentials)
    cp /var/www/html/.env "$TEST_ENV_BACKUP"

    # Switch to test database
    sed -i 's/^DB_DATABASE=.*$/DB_DATABASE=rspade_test/' /var/www/html/.env

    # Add test config to include tests directory in manifest
    if ! grep -q "^RSX_ADDITIONAL_CONFIG=" /var/www/html/.env; then
        echo "RSX_ADDITIONAL_CONFIG=/var/www/html/system/app/RSpade/tests/_lib/rsx_test_config.php" >> /var/www/html/.env
    else
        sed -i 's|^RSX_ADDITIONAL_CONFIG=.*$|RSX_ADDITIONAL_CONFIG=/var/www/html/system/app/RSpade/tests/_lib/rsx_test_config.php|' /var/www/html/.env
    fi

    # Clear Laravel config cache. NO TIMEOUT: this used to be `timeout 10 ... || true`,
# which could expire SILENTLY right after .env was restored - leaving a cached config
# pointing at the TEST database while .env named the dev one. See the no-timeout mandate.
    cd /var/www/html
    php artisan config:clear > /dev/null 2>&1 || true

    TEST_MODE_ACTIVE=true
    echo "[TEST ENV] Test mode active (using rspade_test database)" >&2
}

# Exit test mode - restore original database
function test_mode_exit() {
    if [ "$TEST_MODE_ACTIVE" = false ]; then
        return 0
    fi

    echo "[TEST ENV] Exiting test mode..." >&2

    # Restore original .env
    if [ -f "$TEST_ENV_BACKUP" ]; then
        mv "$TEST_ENV_BACKUP" /var/www/html/.env
    fi

    # Clear Laravel config cache. NO TIMEOUT: this used to be `timeout 10 ... || true`,
# which could expire SILENTLY right after .env was restored - leaving a cached config
# pointing at the TEST database while .env named the dev one. See the no-timeout mandate.
    cd /var/www/html
    php artisan config:clear > /dev/null 2>&1 || true

    TEST_MODE_ACTIVE=false
    echo "[TEST ENV] Test mode exited (restored original database)" >&2
}

# Trap to ensure test mode always exits
function test_trap_exit() {
    test_mode_exit

    # NEVER remove backup files - they contain production credentials
    # Backup files are process-specific (/tmp/rspade_test_env_backup_$$)
    # and will be cleaned up by system /tmp cleanup
}

# Query helper for test database
function test_db_query() {
    mysql -h127.0.0.1 -urspade -prspadepass rspade_test -N -e "$1" 2>/dev/null
}

# Count helper for test database
function test_db_count() {
    local table=$1
    test_db_query "SELECT COUNT(*) FROM $table"
}
