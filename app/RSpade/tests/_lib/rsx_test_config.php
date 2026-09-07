<?php
/**
 * RSpade Test Configuration
 *
 * Additional configuration loaded when RSX_ADDITIONAL_CONFIG env variable is set.
 * It is the test runner's per-run config-injection seam, merged last.
 *
 * Loaded by: test_mode_enter.sh
 * Cleared by: test_mode_exit.sh
 *
 * IT NO LONGER APPENDS THE TESTS DIRECTORY. The test trees are added by
 * _Manifest_Scanner_Helper::_scan_directories() when the process is a test run
 * (Rsx_Test_Abstract::suite_is_running(), the --_test-run flag), which reaches every
 * child a test spawns as well - an env file only reaches processes that read it.
 *
 * The file stays because the seam does: db_reset.sh and test_env.sh assert that
 * RSX_ADDITIONAL_CONFIG points here, and a run that needs a config override has one
 * place to put it.
 */

return [];
