<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Tests\Manifest\Php\Manifest_Query_Fixture_Behaviour;
use App\RSpade\Tests\Manifest\Php\Manifest_Query_Fixture_Contract;
use App\RSpade\Tests\Manifest\Php\Manifest_Query_Fixture_Mid_Abstract;
/**
 * Manifest_Query_Test fixture: a concrete class two levels below the root, which also
 * implements an interface declared in the test tree (itself extending the built-in
 * JsonSerializable) and uses a trait - none of which is a parent class. Instantiatable because
 * the interface requires an instance method.
 */
#[Instantiatable]
class Manifest_Query_Fixture_Leaf extends Manifest_Query_Fixture_Mid_Abstract implements Manifest_Query_Fixture_Contract
{
    use Manifest_Query_Fixture_Behaviour;

    public static function fixture_contract_name(): string
    {
        return 'leaf';
    }

    public function jsonSerialize(): mixed
    {
        return [];
    }
}
