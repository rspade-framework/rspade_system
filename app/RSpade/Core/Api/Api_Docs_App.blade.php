@rsx_id('Api_Docs_App')
{{--
    The API reference console's page shell.

    Returned by Rsx_Api_Docs::page() from whatever route the APPLICATION declared, so this
    view carries no chrome of its own: the console is a single component that owns the whole
    page, including its own in-page navigation.

    The bundle is passed IN rather than named here, the same way Spa_App.blade.php takes one.
    It has to be the application's: a bundle must cover the directory of the controller that
    rendered it, and a framework bundle can never cover an application's controller.
--}}
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>API Reference</title>

    {!! $bundle::render() !!}
</head>

<body class="{{ rsx_body_class() }}">
    <Api_Docs_Console />
</body>

</html>
