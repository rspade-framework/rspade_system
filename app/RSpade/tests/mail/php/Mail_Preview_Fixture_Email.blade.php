@rsx_id('Mail_Preview_Fixture_Email')
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::header($subject ?? 'Preview probe') !!}
<p>{{ $note }}</p>
<p><img src="/favicon.ico" alt="Site icon"></p>
@if (!empty($show_chart))
<p><img src="cid:preview_chart" alt="Chart"></p>
@endif
<p style="text-align: center;">
    <a href="{{ $view_url }}" class="email-button">Open the thing</a>
</p>
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
