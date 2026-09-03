@rsx_id('Api_Docs_App')
{{--
    The API reference console's page shell.

    Returned by Rsx_Api_Docs::page() from whatever route the APPLICATION declared, so this
    view carries no chrome of its own: the console is a single component that owns the whole
    page, including its own in-page navigation.

    THE BUNDLE IS NAMED HERE, and it is the framework's own. Nothing on this page is the
    application's but the route: an app-side bundle could only have listed framework
    directories back to the framework. The render-time controller-coverage check that would
    otherwise object - a framework bundle can never cover an app controller's directory - is
    waived for a framework view, which this is (Rsx_Bundle_Abstract::__validate_path_coverage).
--}}
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>API Reference</title>

    {!! \App\RSpade\Core\Api\Api_Docs_Bundle::render() !!}
</head>

<body class="{{ rsx_body_class() }}">
    <Api_Docs_Console />
</body>

</html>
