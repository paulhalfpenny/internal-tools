@php
  $checkpointShortfall = max(0, $threshold - $hours);
  $remainingHours = max(0, $target - $hours);
@endphp
<x-email-shell
  heading="You're behind on this week's timesheet"
  :subheading="$weekRange"
  :cta-url="$timesheetUrl"
  cta-label="Open my timesheet"
>
  <p style="margin:0 0 16px;">Hi {{ $userFirstName }},</p>

  <p style="margin:0 0 16px;">By the end of Wednesday you've logged <strong>{{ number_format($hours, 1) }} hours</strong>. Your mid-week checkpoint is <strong>{{ number_format($threshold, 1) }} hours</strong>, so you're <strong>{{ number_format($checkpointShortfall, 1) }} hours</strong> short.</p>

  <p style="margin:0 0 24px;">Based on this Wednesday snapshot, you have <strong>{{ number_format($remainingHours, 1) }} hours</strong> remaining to log for the week. The sooner you fill these in, the easier it'll be — and your client reports stay on schedule.</p>

  <p style="margin:0; color:#888; font-size:13px;">Tip: stop and start the timer in the day view — it'll fill in entries automatically.</p>
</x-email-shell>
