<x-email-shell
  heading="Internal Tools Asana sync is paused"
  subheading="Reconnect the designated account to resume hours updates"
  :cta-url="$settingsUrl"
  cta-label="Review Asana integration"
>
  <p style="margin:0 0 16px;">
    Internal Tools could not use its designated Asana sync account, so no hours were written to Asana.
  </p>

  <p style="margin:0 0 16px;">
    <strong>Account:</strong>
    {{ $actorName ? $actorName.($actorEmail ? ' ('.$actorEmail.')' : '') : 'No account designated' }}<br>
    <strong>Reason:</strong> {{ str_replace('_', ' ', $reason) }}
  </p>

  <p style="margin:0; color:#666;">
    Time entries remain saved in Internal Tools and will be retried after the designated account reconnects.
  </p>
</x-email-shell>
