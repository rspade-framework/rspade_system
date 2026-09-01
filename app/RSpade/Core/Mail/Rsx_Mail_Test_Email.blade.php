@rsx_id('Rsx_Mail_Test_Email')
{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::header($subject ?? 'RSpade mail test') !!}

<p>This is the RSpade mail smoke test.</p>

<p>If you are reading it, the queue claimed a row, the builder rendered it, and the
transport accepted it - the whole outbound chain works on this host.</p>

<hr class="email-divider">

<p><strong>Host:</strong> {{ $hostname }}<br>
<strong>Queued at:</strong> {{ \App\RSpade\Core\Time\Rsx_Time::format_datetime($sent_at) }}</p>

<p style="text-align: center;">
    <a href="{{ $app_url }}" class="email-button">Open the application</a>
</p>

<p class="email-muted">Sent by <code>php artisan rsx:mail:test</code>. Nobody subscribed
to anything to receive this.</p>

{!! \App\RSpade\Core\Mail\Rsx_Mail_Layout::footer($unsubscribe_url ?? null) !!}
