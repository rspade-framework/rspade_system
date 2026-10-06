@rsx_id('Mail_Marketing_Fixture_Email')
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::header($subject ?? "Offer") !!}
<p>{{ $note }}</p>
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
