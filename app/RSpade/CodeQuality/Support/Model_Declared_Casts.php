<?php

namespace App\RSpade\CodeQuality\Support;

/**
 * The explicit casts a model states, read by reflection: the $casts default plus a casts()
 * method's answer - the two sources Eloquent's getCasts() merges before RSpade adds its own
 * schema-derived casts (the text-type and money casts among them).
 *
 * A declaration-derived cast is attached only where no cast exists, so an explicit entry for
 * such a column SHADOWS it. TEXT-TYPE-01 and MODEL-COLUMNS-01 read the explicit casts here.
 */
class Model_Declared_Casts
{
    /**
     * @param string $fqcn A model class
     * @return array<string, mixed> column => cast
     */
    public static function of(string $fqcn): array
    {
        $reflection = new \ReflectionClass($fqcn);
        $casts = (array) ($reflection->getDefaultProperties()['casts'] ?? []);

        if ($reflection->hasMethod('casts')) {
            $method = $reflection->getMethod('casts');

            if ($method->getDeclaringClass()->getName() !== \Illuminate\Database\Eloquent\Model::class) {
                try {
                    $method->setAccessible(true);
                    $casts = array_merge($casts, (array) $method->invoke($reflection->newInstanceWithoutConstructor()));
                } catch (\Throwable $unevaluable) {
                    // A casts() body that needs a booted model cannot be read here; the rules
                    // reading it are fatal, so they skip what they cannot prove.
                }
            }
        }

        return $casts;
    }
}
