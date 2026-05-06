@php $shortfall = max(0, $target - $hours); @endphp
<x-email-shell
  heading="You're behind on this week's timesheet"
  :subheading="$weekRange"
  :cta-url="$timesheetUrl"
  cta-label="Open my timesheet"
>
  <p style="margin:0 0 16px;">Hi {{ $userFirstName }},</p>

  <p style="margin:0 0 16px;">By the end of Wednesday you've logged <strong>{{ number_format($hours, 1) }} hours</strong> against a target of <strong>{{ number_format($target, 1) }} hours</strong> for the week. That's below the {{ (int) ($threshold / $target * 100) }}% mid-week checkpoint we use to flag timesheets that need attention.</p>

  <p style="margin:0 0 24px;">You've got <strong>{{ number_format($shortfall, 1) }} hours</strong> to catch up before the end of the week. The sooner you fill these in, the easier it'll be — and your client reports stay on schedule.</p>

  <p style="margin:0; color:#888; font-size:13px;">Tip: stop and start the timer in the day view — it'll fill in entries automatically.</p>
</x-email-shell>
