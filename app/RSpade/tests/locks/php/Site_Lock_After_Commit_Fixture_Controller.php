<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Session\Session;
use App\RSpade\Lib\Messaging\Mail\Email_Blocked_Address_Model;

/**
 * Ajax surfaces for Site_Lock_After_Commit_Test: a #[Writer] and a #[Manual_Lock] endpoint that
 * write one framework site-scoped row and register two DB::afterCommit() callbacks, the first
 * throwing, the second recording that it ran. Indexed only under a test run.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth].
 */
class Site_Lock_After_Commit_Fixture_Controller extends Rsx_Controller_Abstract
{
    public const CALLBACK_MESSAGE = 'site lock after-commit fixture callback threw';

    /** How many times the second (well-behaved) after-commit callback ran. */
    public static int $second_ran = 0;

    #[Ajax_Endpoint]
    #[Writer]
    #[Auth('public')]
    public static function writer_after_commit_throws(Request $request, array $params = [])
    {
        static::block((string) $params['email']);
        static::register_callbacks();

        if (!empty($params['throw'])) {
            throw new RuntimeException('writer_after_commit_throws threw before its commit');
        }

        return ['email' => $params['email']];
    }

    #[Ajax_Endpoint]
    #[Manual_Lock]
    #[Auth('public')]
    public static function manual_after_commit_throws(Request $request, array $params = [])
    {
        $token = RsxLocks::site_write_lock((int) Session::get_site_id());

        try {
            static::block((string) $params['email']);
            static::register_callbacks();
        } finally {
            RsxLocks::release_lock($token);
        }

        return ['email' => $params['email']];
    }

    /** The two after-commit callbacks: the first throws, the second counts. */
    public static function register_callbacks(): void
    {
        DB::afterCommit(function () {
            throw new RuntimeException(self::CALLBACK_MESSAGE);
        });
        DB::afterCommit(function () {
            static::$second_ran++;
        });
    }

    public static function block(string $email): void
    {
        $row = new Email_Blocked_Address_Model();
        $row->email = $email;
        $row->reason = 'site lock after-commit test';
        $row->save();
    }
}
