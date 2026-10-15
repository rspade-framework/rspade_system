<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Mail\Rsx_Mail_Builder;
use App\RSpade\Core\Mail\Rsx_Mail_Test_Email;
use App\RSpade\Core\Mail\Rsx_Mail_Transport;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Mail\Php\Mail_Preview_Fixture_Email;

/**
 * Rsx_Mail_Preview_Test - the email catalogue, and an email instance rendered without
 * being sent.
 *
 * Rsx_Mail::preview() is worth having only if it shows what a recipient receives, so the
 * central test here renders the SAME instance both ways - queued and built by the send
 * path, and previewed - and requires the two bodies to be identical, the unsubscribe
 * link (signed for a recipient vs the preview placeholder) being the one sanctioned
 * difference. The rest prove it is displayable (every local image a data: URI), that it
 * touches no table, and that the catalogue is the manifest's own table.
 */
class Rsx_Mail_Preview_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /** A 1x1 PNG, so an embedded part is a real image. */
    private const ONE_PIXEL_PNG =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public static function setup()
    {
        static::__acting_as_site(self::SITE_ID);
    }

    private static function __png_path(): string
    {
        $path = Rsx_Project_Paths::tmp_path('mail_preview_probe_' . uniqid() . '.png');
        file_put_contents_safe($path, base64_decode(self::ONE_PIXEL_PNG));

        return $path;
    }

    /**
     * Every src="..." value in $html, in order.
     *
     * @return array<int,string>
     */
    private static function __srcs(string $html): array
    {
        preg_match_all('/src="([^"]*)"/', $html, $matches);

        return $matches[1];
    }

    /**
     * Row counts of every table a send writes and a preview must not.
     *
     * @return array<string,int>
     */
    private static function __table_counts(): array
    {
        $counts = [];

        foreach (['_email_queue', '_email_attachments', '_file_storage'] as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }

        return $counts;
    }

    // =========================================================================
    // THE CATALOGUE
    // =========================================================================

    /**
     * catalogue() IS the baked table - same entries, same shape, sorted by view id - and
     * it lists framework emails beside application ones.
     */
    public static function test_the_catalogue_is_the_baked_email_table()
    {
        $baked = Manifest::get_full_manifest()['data']['emails'];
        ksort($baked);

        $catalogue = Rsx_Mail::catalogue();

        static::__assert_equals($baked, $catalogue, 'exactly the table the manifest baked');
        static::__assert_equals(array_keys($baked), array_keys($catalogue), 'in view-id order');
        static::__assert_array_has_key('Rsx_Mail_Test_Email', $catalogue, "the framework's own email is listed");
        static::__assert_equals(
            Rsx_Mail_Test_Email::class,
            $catalogue['Rsx_Mail_Test_Email']['class'],
            'each entry names the class to construct'
        );
        static::__assert_equals(
            ['class', 'file', 'view_id', 'category'],
            array_keys($catalogue['Mail_Preview_Fixture_Email']),
            'every entry carries class, file, view_id and category'
        );
    }

    /**
     * rsx:mail:test --email= resolves its name through the catalogue: an email class that
     * exists only in this test tree is found and its sample() queued.
     */
    public static function test_rsx_mail_test_resolves_a_catalogue_email()
    {
        $previous = config('rsx.mail.delivery');
        Rsx_Mail_Transport::$override_for_tests = null;

        // Suppressed never opens a transport: what is under test is which class was sent.
        config(['rsx.mail.delivery' => 'suppressed']);

        try {
            static::__acting_as_site(self::SITE_ID);

            $exit_code = Artisan::call('rsx:mail:test', [
                'address' => 'catalogue_' . uniqid() . '@example.com',
                '--email' => 'Mail_Preview_Fixture_Email',
                '--json' => true,
            ]);
            $output = Artisan::output();
        } finally {
            config(['rsx.mail.delivery' => $previous]);
        }

        static::__assert_equals(0, $exit_code, 'the catalogue email was sent: ' . $output);

        $payload = json_decode(trim($output), true);

        static::__assert_equals('Mail_Preview_Fixture_Email', $payload['email_class'], 'the named class is what was queued');
    }

    // =========================================================================
    // THE PREVIEW IS THE SEND'S RENDERING
    // =========================================================================

    /**
     * One instance, queued and built by the send path, then previewed: the displayable
     * bodies are identical but for the unsubscribe link, which a send signs for its
     * recipient and a preview fills with the placeholder.
     */
    public static function test_a_preview_renders_exactly_what_a_send_renders()
    {
        $png = static::__png_path();

        try {
            $build = fn () => (new Mail_Preview_Fixture_Email('Same pipeline', null, true))
                ->embed('preview_chart', $png);

            static::__acting_as_site(self::SITE_ID);
            $address = 'preview_same_' . uniqid() . '@example.com';

            $previous = config('rsx.mail.dev_site.domain_whitelist');
            config(['rsx.mail.dev_site.domain_whitelist' => 'example.com']);

            try {
                $row = $build()->to($address)->send();
            } finally {
                config(['rsx.mail.dev_site.domain_whitelist' => $previous]);
            }

            Rsx_Mail_Builder::build($row);
            $row = Email_Queue_Model::find($row->id);

            $preview = Rsx_Mail::preview($build());
        } finally {
            @unlink($png);
        }

        $signed = Rsx_Mail::unsubscribe_url($address, Rsx_Mail::NOTIFICATION, self::SITE_ID);
        $placeholder = Rsx_Mail::preview_unsubscribe_url();

        $sent_html = str_replace(
            htmlspecialchars($signed, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8'),
            Rsx_Mail::displayable_html($row)
        );

        static::__assert_contains(
            htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8'),
            $preview['html'],
            'a NOTIFICATION preview carries the placeholder unsubscribe link'
        );
        static::__assert_equals($sent_html, $preview['html'], 'the preview body IS the sent body');
        static::__assert_equals(
            str_replace($signed, $placeholder, (string) $row->rendered_text),
            $preview['text'],
            'and the text part is the one a send derives'
        );
        static::__assert_equals('Same pipeline', $preview['subject'], 'the subject is the email\'s subject()');
    }

    /**
     * The stylesheet is inlined onto the elements exactly as for a send, and every local
     * image - the root-relative asset and the ->embed() part - is a data: URI of its bytes.
     */
    public static function test_a_preview_is_inlined_and_its_local_images_are_data_uris()
    {
        $png = static::__png_path();

        try {
            $preview = Rsx_Mail::preview(
                (new Mail_Preview_Fixture_Email('Displayable', null, true))->embed('preview_chart', $png)
            );
        } finally {
            @unlink($png);
        }

        $html = $preview['html'];

        static::__assert_true(
            (bool) preg_match('/<a [^>]*class="email-button"[^>]*style="[^"]+"/', $html)
                || (bool) preg_match('/<a [^>]*style="[^"]+"[^>]*class="email-button"/', $html),
            'the email stylesheet is inlined onto the button'
        );
        static::__assert_contains('<style>', $html, 'and the compiled sheet also rides in <head>');
        static::__assert_false(str_contains($html, 'cid:'), 'no cid: reference is left for a browser to fail on');

        $srcs = static::__srcs($html);
        static::__assert_count(2, $srcs, 'the root-relative image and the embedded chart');

        $favicon_path = \App\RSpade\Core\Dispatch\AssetHandler::find_public_asset('favicon.ico');

        static::__assert_true(
            str_starts_with($srcs[0], 'data:') && str_ends_with($srcs[0], base64_encode(file_get_contents($favicon_path))),
            'the root-relative image is a data: URI of the asset\'s current bytes'
        );
        static::__assert_equals(
            'data:image/png;base64,' . self::ONE_PIXEL_PNG,
            $srcs[1],
            'the ->embed() part is a data: URI of the bytes it was given'
        );
        static::__assert_contains('Displayable', $preview['text'], 'the text part comes back too');
    }

    /**
     * A category with no unsubscribe link gets none in a preview either.
     */
    public static function test_a_transactional_preview_carries_no_unsubscribe_link()
    {
        $preview = Rsx_Mail::preview(Rsx_Mail_Test_Email::sample());

        static::__assert_equals('RSpade mail test', $preview['subject'], 'the subject');
        static::__assert_false(
            str_contains($preview['html'], '/_mail/unsubscribe'),
            'a transactional email offers no unsubscribe, preview or not'
        );
    }

    // =========================================================================
    // NOTHING IS PERSISTED, NOTHING IS REQUIRED
    // =========================================================================

    /**
     * No recipient, and no row anywhere: not in the queue, not an attachment row, not a
     * blob - for an email with an embedded part and an auto-embedded asset, the two things
     * a send DOES store.
     */
    public static function test_a_preview_needs_no_recipient_and_persists_nothing()
    {
        $png = static::__png_path();
        $before = static::__table_counts();

        try {
            $preview = Rsx_Mail::preview(
                (new Mail_Preview_Fixture_Email('Nothing stored', null, true))->embed('preview_chart', $png)
            );
        } finally {
            @unlink($png);
        }

        static::__assert_contains('Nothing stored', $preview['html'], 'the preview rendered with no ->to()');
        static::__assert_equals($before, static::__table_counts(), 'no queue, attachment or storage row was written');
    }

    /**
     * A template naming a cid: nothing was embedded under fails the preview exactly as it
     * would fail the send.
     */
    public static function test_an_unembedded_cid_throws_as_a_send_would()
    {
        static::__assert_throws(
            \RuntimeException::class,
            fn () => Rsx_Mail::preview(new Mail_Preview_Fixture_Email('No chart', null, true)),
            "->embed('preview_chart'"
        );
    }
}
