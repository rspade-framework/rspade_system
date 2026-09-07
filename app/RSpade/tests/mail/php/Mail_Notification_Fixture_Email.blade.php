@rsx_id('Mail_Notification_Fixture_Email')
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::header($subject ?? "Notice") !!}
<p>{{ $note }}</p>
@if (!empty($show_image))
<p><img src="cid:fixture_image" alt="Fixture image"></p>
@endif
<p style="text-align: center;">
    <a href="{{ $view_url }}" class="email-button">Open the thing</a>
</p>
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
