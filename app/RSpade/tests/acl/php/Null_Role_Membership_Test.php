<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Acl\Php;

use Symfony\Component\Process\Process;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Permission\Permission_Abstract;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A membership with no role (users.role_id NULL) holds no role and no permissions
 * (rsx:man auth, ROLES): it fails every has_role() floor and every can_admin_role()
 * question, in PHP and in the JS mirror.
 *
 * has_role() is the comparison role_id <= X, and both PHP and JavaScript order null below
 * every integer, so the bare comparison ranks a role-less member above the most privileged
 * role. Every role the application declares is asked, derived from the role_id enum.
 *
 * Runs in the default per-test transaction.
 */
class Null_Role_Membership_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private static function __make_role_less_member(): User_Model
    {
        static::__acting_as_site(self::SITE_ID);

        $user = new User_Model();
        $user->site_id = self::SITE_ID;
        $user->login_user_id = null;
        $user->first_name = 'No';
        $user->last_name = 'Role';
        $user->email = 'null_role_' . uniqid() . '@example.com';
        $user->role_id = null;
        $user->is_enabled = true;
        $user->save();

        return User_Model::find($user->id);
    }

    /** @return int[] */
    private static function __declared_roles(): array
    {
        return array_map('intval', array_keys(User_Model::role_id__enum()));
    }

    public static function test_role_less_member_fails_every_floor_and_admin_question()
    {
        $user = static::__make_role_less_member();
        static::__assert_null($user->role_id, 'the member was stored with no role');

        foreach (static::__declared_roles() as $role_id) {
            static::__assert_false($user->has_role($role_id), "has_role({$role_id}) for a member with no role");
            static::__assert_false($user->can_admin_role($role_id), "can_admin_role({$role_id}) for a member with no role");
        }

        static::__assert_equals([], $user->get_resolved_permissions(), 'a member with no role resolves no permissions');
    }

    public static function test_facade_answers_false_for_the_signed_in_role_less_member()
    {
        $user = static::__make_role_less_member();
        static::__acting_as_user((int) $user->id);

        foreach (static::__declared_roles() as $role_id) {
            static::__assert_false(Permission_Abstract::has_role($role_id), "Permission::has_role({$role_id})");
            static::__assert_false(Permission_Abstract::can_admin_role($role_id), "Permission::can_admin_role({$role_id})");
        }

        static::__assert_throws(AjaxUnauthorizedException::class, function () {
            Permission_Abstract::require_role(static::most_privileged_role_id());
        });
    }

    public static function test_js_mirror_answers_false_for_a_role_less_member()
    {
        $user = static::__make_role_less_member();
        $payload = json_encode([
            'user' => $user->toArray(),
            'roles' => static::__declared_roles(),
        ]);

        // The mirror reads window.rsxapp.user; it is the class file as shipped, evaluated in
        // node with that one global supplied.
        $script = <<<'JS'
const fs = require('fs');
const input = JSON.parse(process.argv[2]);
global.window = {rsxapp: {user: input.user}};
eval(fs.readFileSync(process.argv[1], 'utf8') + '\nglobal.Permission_Under_Test = Permission;');
const answers = input.roles.map(r => [r, Permission_Under_Test.has_role(r), Permission_Under_Test.can_admin_role(r)]);
process.stdout.write(JSON.stringify(answers));
JS;

        $process = new Process(['node', '-e', $script, base_path('app/RSpade/Core/Js/Permission.js'), $payload]);
        $process->setTimeout(null);
        $process->run();
        static::__assert_equals(0, $process->getExitCode(), 'node failed: ' . $process->getErrorOutput());

        $answers = json_decode($process->getOutput(), true);
        static::__assert_count(count(static::__declared_roles()), $answers);
        foreach ($answers as [$role_id, $has_role, $can_admin]) {
            static::__assert_false($has_role, "Permission.has_role({$role_id}) in JS");
            static::__assert_false($can_admin, "Permission.can_admin_role({$role_id}) in JS");
        }
    }
}
