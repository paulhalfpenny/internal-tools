@php $shortfall = max(0, $target - $hours); @endphp
<x-email-shell
  heading="Your timesheet for {{ $monthLabel }} is incomplete"
  :subheading="$monthLabel"
  :cta-url="$timesheetUrl"
  :cta-label="'Review '.$monthLabel"
>
  <p style="margin:0 0 16px;">Hi {{ $userFirstName }},</p>

  <p style="margin:0 0 16px;">For {{ $monthLabel }} you logged <strong>{{ number_format($hours, 1) }} of {{ number_format($target, 1) }} hours</strong>. That's <strong>{{ number_format($shortfall, 1) }} hours</strong> short of the monthly expectation.</p>

  <p style="margin:0 0 24px;">Please review the month and fill in any missing entries. Once the month is finalised it gets harder to reconcile against client work, so the sooner the better.</p>
</x-email-shell>
