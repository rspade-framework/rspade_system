# Archived - polymorphic

## `Polymorphic_Morphto_Removal_Test.php.retired`

Retired 2026-08-09. It pinned the ABSENCE of `morphTo()`-backed relations on the two
framework-core type-ref models, on the premise that "morphTo() reads the raw integer and
crashes on a `$type_ref_columns` model".

That premise no longer holds. `Type_Ref_Registry::register_morph_map()` now registers each
type-ref integer id as a morph-map alias alongside the class name, so stock Eloquent morph
relations resolve a raw integer discriminator directly. The constraint the test enforced
became the opposite of the standard.

Replaced by `tests/polymorphic/php/Polymorphic_Morph_Relations_Test.php`, which proves the
positive behavior (lazy read, associate/dissociate write, morphMany/morphOne constraints,
whereMorphedTo/whereHasMorph, and the load-bearing morph-map alias ordering).
