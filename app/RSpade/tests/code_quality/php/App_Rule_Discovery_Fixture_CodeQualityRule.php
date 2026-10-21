<?php

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;

/**
 * Fixture for App_Rule_Discovery_Test: a rule class OUTSIDE the framework's Rules/ directory.
 *
 * Discovery reads every concrete class the manifest index says extends
 * CodeQualityRule_Abstract, so this class - indexed only while rsx:test runs - is discovered
 * as an application rule exactly as a class under rsx/ is. It is deliberately inert: it
 * matches no file pattern and never runs at manifest time, so a pass that discovers it has
 * nothing to say about anything.
 */
class App_Rule_Discovery_Fixture_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'APP-DISCOVERY-FIXTURE-01';
    }

    public function get_name(): string
    {
        return 'Application Rule Discovery Fixture';
    }

    public function get_description(): string
    {
        return 'A test fixture proving application rules are discovered by class. It matches nothing.';
    }

    public function get_file_patterns(): array
    {
        return [];
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
    }
}
