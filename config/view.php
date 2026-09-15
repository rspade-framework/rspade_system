<?php

return [

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    |
    | Most templating systems load templates from disk. Here you may specify
    | an array of paths that should be checked for your views. Of course
    | the usual Laravel view path has already been registered for you.
    |
    */

    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    |
    | This option determines where all the compiled Blade templates will be
    | stored for your application. Typically, this is within the storage
    | directory. However, as usual, you are free to change this value.
    |
    */

    // Compiled Blade is a BUILD OUTPUT and lives in the build tree with every other
    // one. No realpath(): the directory legitimately does not exist yet on a box that
    // has not built, and realpath() would answer false and send Blade to the CWD.
    'compiled' => \App\RSpade\Core\Paths\Rsx_Project_Paths::views_compiled_dir(),

];
