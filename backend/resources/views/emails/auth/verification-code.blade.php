<!doctype html>
<html lang="en">
<body style="margin:0;background:#f3f4f6;font-family:Arial,sans-serif;color:#111827">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
    <tr><td align="center" style="padding:32px 16px">
      <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden">
        <tr><td style="background:#0f766e;padding:20px 28px;color:#ffffff;font-size:20px;font-weight:bold">BrathaMatu</td></tr>
        <tr><td style="padding:28px">
          <h1 style="font-size:20px;margin:0 0 16px">Verify your email address</h1>
          <p>Hello {{ $recipientName }},</p>
          <p>Use this single-use code to verify your BrathaMatu account:</p>
          <p style="font-size:28px;font-weight:bold;letter-spacing:0.2em">{{ $code }}</p>
          <p style="color:#6b7280">This code expires in 15 minutes. If you did not request it, you can ignore this email.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
