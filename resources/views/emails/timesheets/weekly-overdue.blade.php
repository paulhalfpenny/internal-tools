@php $shortfall = max(0, $target - $hours); @endphp
<x-email-shell
  heading="Your timesheet for last week is incomplete"
  :subheading="$weekRange"
  :cta-url="$timesheetUrl"
  cta-label="Back-fill last week"
>
  <p style="margin:0 0 16px;">Hi {{ $userFirstName }},</p>

  <p style="margin:0 0 16px;">Last week ({{ $weekRange }}) you logged <strong>{{ number_format($hours, 1) }} of {{ number_format($target, 1) }} hours</strong>. That's a shortfall of <strong>{{ number_format($shortfall, 1) }} hours</strong>.</p>

  <p style="margin:0 0 24px;">Please back-fill the missing time as soon as you can. Client reporting depends on accurate timesheets, so we'd really appreciate it.</p>

  <p style="margin:0; color:#888; font-size:13px;">If you were on holiday or off sick and this is incorrect, ask an admin to set your "notifications paused until" date so you don't get chased.</p>
</x-email-shell>
