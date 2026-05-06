@props([
  'heading' => 'Filter Internal Tools',
  'subheading' => null,
  'ctaUrl' => null,
  'ctaLabel' => 'Open my timesheet',
])
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $heading }}</title>
  <!--[if mso]><style>table { border-collapse: collapse; } td { font-family: Arial, sans-serif; }</style><![endif]-->
</head>
<body style="margin:0; padding:0; background:#F2F2F2; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#F2F2F2; padding:32px 16px;">
    <tr>
      <td align="center">
        <!--[if mso]><table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;"><tr><td><![endif]-->
        <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.06);">
          <tr>
            <td style="background-color:#002F5F; background-image:linear-gradient(135deg,#002F5F,#004080); padding:32px 40px 28px; text-align:center;">
              <a href="{{ config('app.url') }}" style="text-decoration:none;">
                <img src="{{ rtrim(config('app.url'), '/') }}/assets/filter-logo-white-rgb.png" alt="Filter Internal Tools" width="160" height="38" border="0" style="display:block; margin:0 auto 20px; width:160px; height:auto;">
              </a>
              <h1 style="color:#ffffff; margin:0; font-size:22px; font-weight:700;">{{ $heading }}</h1>
              @if ($subheading)
                <p style="color:#cccccc; margin:8px 0 0; font-size:14px;">{{ $subheading }}</p>
              @endif
            </td>
          </tr>

          <tr>
            <td style="padding:32px 40px 8px; color:#333; font-size:15px; line-height:1.55;">
              {{ $slot }}
            </td>
          </tr>

          @if ($ctaUrl)
            <tr>
              <td style="padding:8px 40px 32px;" align="center">
                <table cellpadding="0" cellspacing="0" style="margin:0 auto;">
                  <tr>
                    <td align="center" style="background-color:#E1236C; border-radius:6px;">
                      <a href="{{ $ctaUrl }}" style="display:inline-block; padding:14px 28px; color:#ffffff; text-decoration:none; font-weight:600; font-size:15px; letter-spacing:0.2px;">{{ $ctaLabel }}</a>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
          @endif

          <tr>
            <td style="background:#F2F2F2; padding:20px 40px; text-align:center; color:#888; font-size:12px; line-height:1.5;">
              You're receiving this because your timesheet activity is below target.<br>
              Manage notification preferences in
              <a href="{{ rtrim(config('app.url'), '/') }}/timesheet" style="color:#E1236C; text-decoration:none;">Internal Tools</a>.
            </td>
          </tr>
        </table>
        <!--[if mso]></td></tr></table><![endif]-->
      </td>
    </tr>
  </table>
</body>
</html>
