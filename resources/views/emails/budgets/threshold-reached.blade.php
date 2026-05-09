@php
    $isOver = $threshold >= 100;
    $heading = $isOver ? 'Project over budget' : 'Project at '.$threshold.'% of budget';
    $subheading = $client ? $client.' — '.$projectName : $projectName;
    $varianceColour = $isOver ? '#E1236C' : '#002F5F';
@endphp

<x-email-shell
  :heading="$heading"
  :subheading="$subheading"
  :cta-url="$projectBudgetUrl"
  cta-label="Open project budget"
>
  <p style="margin:0 0 16px;">
    <strong>{{ $projectName }}</strong> has reached
    <strong style="color: {{ $varianceColour }};">{{ number_format($percentUsed, 1) }}%</strong>
    of {{ $isOver ? 'its budget' : 'budget' }} ({{ $threshold }}% threshold crossed{{ $periodKey === 'lifetime' ? '' : ' for '.$periodLabel }}).
  </p>

  <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin:0 0 24px;">
    <tr>
      <td style="padding:12px 16px; border:1px solid #e5e5e5; border-right:0; background:#FAFAFA; font-size:13px; color:#666; width:50%;">
        Budget
      </td>
      <td style="padding:12px 16px; border:1px solid #e5e5e5; background:#FAFAFA; font-size:13px; color:#666; width:50%;">
        Spent
      </td>
    </tr>
    <tr>
      <td style="padding:14px 16px; border:1px solid #e5e5e5; border-top:0; border-right:0; font-size:18px; font-weight:600; color:#002F5F;">
        £{{ number_format($budgetAmount, 2) }}
      </td>
      <td style="padding:14px 16px; border:1px solid #e5e5e5; border-top:0; font-size:18px; font-weight:600; color: {{ $varianceColour }};">
        £{{ number_format($actualAmount, 2) }}
      </td>
    </tr>
  </table>

  <p style="margin:0; color:#888; font-size:13px;">
    @if ($isOver)
      The project has spent more than its allocated budget. Review and adjust scope or pricing as needed.
    @else
      You're approaching the budget ceiling. Keep an eye on logged hours over the coming days.
    @endif
  </p>
</x-email-shell>
