<x-email-shell
  :heading="'Timesheet status — '.$weekRange"
  :subheading="$isAdminDigest ? 'Team overview' : 'Your direct reports'"
  :cta-url="$adminUrl"
  cta-label="Open admin timesheets"
>
  <p style="margin:0 0 16px;">Hi {{ $managerFirstName }},</p>

  @if (count($rows) === 0)
    <p style="margin:0 0 16px;">Nice — every {{ $isAdminDigest ? 'active user' : 'direct report' }} is on or above target this week. Nothing to chase.</p>
  @else
    <p style="margin:0 0 16px;">The following {{ $isAdminDigest ? 'team members' : 'direct reports' }} are below their weekly target so far this week:</p>

    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin:0 0 24px;">
      <thead>
        <tr>
          <th align="left" style="padding:10px 8px; border-bottom:2px solid #002F5F; font-size:13px; color:#002F5F;">Name</th>
          <th align="right" style="padding:10px 8px; border-bottom:2px solid #002F5F; font-size:13px; color:#002F5F;">Logged</th>
          <th align="right" style="padding:10px 8px; border-bottom:2px solid #002F5F; font-size:13px; color:#002F5F;">Target</th>
          <th align="right" style="padding:10px 8px; border-bottom:2px solid #002F5F; font-size:13px; color:#002F5F;">Gap</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($rows as $row)
          <tr>
            <td style="padding:10px 8px; border-bottom:1px solid #eee; font-size:14px;">{{ $row['name'] }}</td>
            <td align="right" style="padding:10px 8px; border-bottom:1px solid #eee; font-size:14px;">{{ number_format($row['hours'], 1) }}h</td>
            <td align="right" style="padding:10px 8px; border-bottom:1px solid #eee; font-size:14px;">{{ number_format($row['target'], 1) }}h</td>
            <td align="right" style="padding:10px 8px; border-bottom:1px solid #eee; font-size:14px; color:#E1236C; font-weight:600;">−{{ number_format($row['target'] - $row['hours'], 1) }}h</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</x-email-shell>
