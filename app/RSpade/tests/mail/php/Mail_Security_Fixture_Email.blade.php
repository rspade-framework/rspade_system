@rsx_id('Mail_Security_Fixture_Email')
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::header($subject ?? "Your sign-in code") !!}
<p>{{ $note }}</p>
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
