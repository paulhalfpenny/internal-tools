<x-email-shell
  heading="Your Asana connection has dropped"
  subheading="Reconnect to resume task syncing"
  :cta-url="$reconnectUrl"
  cta-label="Reconnect Asana"
>
  <p style="margin:0 0 16px;">Hi {{ $userFirstName }},</p>

  <p style="margin:0 0 16px;">
    Internal Tools can no longer reach Asana on your behalf.
    @if ($lostAtLabel)
      It stopped working on <strong>{{ $lostAtLabel }}</strong>.
    @endif
    This usually means the connection expired or was revoked in Asana.
  </p>

  <p style="margin:0 0 24px;">While it's disconnected, pulling Asana tasks and pushing logged hours back to Asana are paused for you. Reconnecting takes a few seconds and nothing else needs changing.</p>

  <p style="margin:0; color:#888; font-size:13px;">If you disconnected Asana deliberately, you can ignore this — you won't be reminded again unless you reconnect and it drops a second time.</p>
</x-email-shell>
