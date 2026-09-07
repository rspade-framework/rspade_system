<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Attachments\Php;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use App\RSpade\Core\Auth\Staff_Authorizable;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Attachments\Php\Attachment_Fileable_Policy_Fixture;

/**
 * THE POLYMORPHIC PARENT AND THE STAFF READ SEAM on File_Attachment_Model.
 *
 * The framework owns fileable_type / fileable_id and declares fileable_type in
 * $type_ref_columns, but shipped no accessor for its own polymorphic parent, so every
 * application hand-rolled one - and a hand-rolled morph accessor is one of the reasons an
 * application ends up cloning the whole model. fileable() is the framework's own, and the
 * integer discriminator is transparent through it: the morph map registers the type-ref id
 * as an alias, so STOCK morphTo() resolves it and nothing here resolves anything by hand.
 *
 * Staff_Authorizable is the staff-realm twin of Portal_Authorizable: can_view() answers
 * "may this staff user see this row" per record and scope_can_view() answers it per set, in
 * SQL, before pagination. Its defaults are PERMISSIVE and that is deliberate - the
 * framework does not know an application's visibility rules, and a fail-closed default
 * would mean nothing renders until every model overrides the pair. The framework owns the
 * SEAM; the application owns the POLICY, and a class's own method always beats a trait's,
 * so adopting the trait can never shadow a policy the model already states.
 */
class Attachment_Fileable_Test extends Rsx_Test_Abstract
{
    private const CATEGORY = 'fileable_test';

    /**
     * A real seeded site id (> 0), set as the session site so the site-scoped save hook and
     * the site FK are both satisfied in the CLI harness.
     */
    private static function __site_id(): int
    {
        $id = (int) Site_Model::where('id', '>', 0)->orderBy('id')->value('id');
        Session::set_site_id($id);

        return $id;
    }

    private static function __make(): File_Attachment_Model
    {
        return File_Attachment_Model::create_from_string(
            'fileable-' . uniqid(),
            'doc.txt',
            ['site_id' => static::__site_id()]
        );
    }

    /** Site_Model rows are the convenient stand-in for "a record that can own attachments". */
    private static function __owner(): Site_Model
    {
        return Site_Model::find(static::__site_id());
    }

    // --- fileable() -------------------------------------------------------------------------

    /**
     * The property form is the one an application writes, and it must come back as the real
     * parent record - resolved through the integer discriminator, with no help from anybody.
     */
    public static function test_fileable_resolves_the_real_parent_record()
    {
        $owner = static::__owner();
        $attachment = static::__make();
        $attachment->add_to($owner, self::CATEGORY);

        $reloaded = File_Attachment_Model::find($attachment->id);
        $parent = $reloaded->fileable;

        static::__assert_not_null($parent, 'the polymorphic parent resolves');
        static::__assert_instance_of(Site_Model::class, $parent, 'and it is the owning record itself');
        static::__assert_equals((int) $owner->id, (int) $parent->id, 'the right row of it');
    }

    /** An unclaimed upload has no parent, and says so rather than throwing. */
    public static function test_an_unattached_upload_has_no_fileable()
    {
        $attachment = static::__make();

        static::__assert_null(
            File_Attachment_Model::find($attachment->id)->fileable,
            'nothing has claimed it, so there is no parent to return'
        );
    }

    /**
     * The METHOD form is the relation object, which is what a caller needs to reach past the
     * accessor - the trashed-parent case being the obvious one.
     */
    public static function test_the_method_form_is_a_morph_to_relation()
    {
        $owner = static::__owner();
        $attachment = static::__make();
        $attachment->add_to($owner, self::CATEGORY);

        $relation = File_Attachment_Model::find($attachment->id)->fileable();

        static::__assert_instance_of(MorphTo::class, $relation, 'the method returns the relation itself');
        static::__assert_instance_of(
            MorphTo::class,
            File_Attachment_Model::find($attachment->id)->fileable()->withTrashed(),
            'so a caller can widen it - withTrashed() is still a MorphTo'
        );
    }

    /** The relationship is declared to the ORM, not just to PHP. */
    public static function test_fileable_is_a_declared_relationship()
    {
        static::__assert_true(
            in_array('fileable', File_Attachment_Model::get_relationships(), true),
            'the Relationship attribute is what makes the property form and the JS stub work'
        );
    }

    // --- Staff_Authorizable ------------------------------------------------------------------

    /** The permissive default: the framework supplies the seam, never a policy. */
    public static function test_can_view_defaults_to_permissive()
    {
        static::__assert_true(
            static::__make()->can_view(null),
            'the framework does not know this application visibility rules, so it restricts nothing'
        );
    }

    /** The set-based companion returns the builder unconstrained, for the same reason. */
    public static function test_scope_can_view_returns_the_query_unconstrained()
    {
        $query = File_Attachment_Model::query();
        $before = $query->toSql();

        $returned = File_Attachment_Model::scope_can_view($query, null);

        static::__assert_true($returned === $query, 'the same builder comes back');
        static::__assert_equals($before, $returned->toSql(), 'with no clause added to it');
    }

    /**
     * THE CONTRACT THAT MAKES THE TRAIT SAFE TO ADOPT: a model that states a real policy
     * wins, because a class own method always beats a trait method. Adopting the trait can
     * therefore never shadow a rule a model already had.
     */
    public static function test_a_model_that_states_a_policy_beats_the_trait()
    {
        $subject = new Attachment_Fileable_Policy_Fixture();

        static::__assert_false($subject->can_view(null), 'the fixture own can_view() is what runs');
        static::__assert_equals(
            'constrained',
            Attachment_Fileable_Policy_Fixture::scope_can_view('unconstrained', null),
            'and its own scope_can_view() too'
        );
    }
}
