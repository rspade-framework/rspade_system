<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Sso\Php;

use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;

/**
 * A Socialite provider that never leaves the process.
 *
 * IT IS TWO TEST FIXTURES IN ONE, and that is deliberate. It stands in for a real identity
 * provider so the engine can be driven end to end with no network and no credentials - and
 * because the ONLY way to reach it is rsx.sso.custom, every test that uses it is also a test
 * of the custom-provider seam. If the seam breaks, this whole file stops working, which is a
 * far louder signal than one test named after the seam.
 *
 * The identity it returns is carried on the CALLBACK REQUEST as a JSON `fake_identity`
 * parameter, not in static state, so two tests can never leak into one another and a test
 * reads like the ceremony it is describing.
 *
 * user() is overridden rather than getUserByToken(), so no token exchange is attempted:
 * everything above it - state verification, the parked window, the linked/unlinked branch,
 * the gates - runs exactly as it does for a real provider.
 *
 * #[Instantiatable] is on THIS class and not on the parent, which is where MANIFEST-INST-01
 * would normally want it: the parent is a vendor class and every Socialite driver is an
 * instance by construction - it carries the request, the credentials and the redirect URL for
 * one ceremony.
 */
#[Instantiatable]
class Fake_Sso_Provider extends AbstractProvider implements ProviderInterface
{
    /** The authorize endpoint a test parses out of the redirect. Nothing listens on it. */
    public const AUTHORIZE_URL = 'https://fake-provider.test/authorize';

    /** The request parameter carrying the identity this ceremony should return. */
    public const IDENTITY_PARAM = 'fake_identity';

    protected $scopeSeparator = ' ';

    protected $scopes = ['openid', 'email'];

    /**
     * {@inheritdoc}
     */
    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase(self::AUTHORIZE_URL, $state);
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl()
    {
        return 'https://fake-provider.test/token';
    }

    /**
     * {@inheritdoc}
     */
    protected function getUserByToken($token)
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    protected function mapUserToObject(array $user)
    {
        return (new User())->setRaw($user)->map([
            'id' => $user['id'] ?? null,
            'email' => $user['email'] ?? null,
            'name' => $user['name'] ?? null,
            'nickname' => $user['nickname'] ?? null,
            'avatar' => $user['avatar'] ?? null,
        ]);
    }

    /**
     * The identity this ceremony asserts, read off the callback request.
     *
     * No HTTP happens. A test that supplies no fake_identity gets one with no id, which is
     * how the "the provider told us nothing usable" path is exercised.
     *
     * @return User
     */
    public function user()
    {
        $raw = json_decode((string) $this->request->input(self::IDENTITY_PARAM, '{}'), true);

        return $this->mapUserToObject(is_array($raw) ? $raw : []);
    }
}
