<!doctype html>
<html lang="en">
<body style="margin:0;background:#f3f4f6;font-family:Arial,sans-serif;color:#111827">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
    <tr><td align="center" style="padding:32px 16px">
      <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden">
        <tr><td style="background:#0f766e;padding:20px 28px;color:#ffffff;font-size:20px;font-weight:bold">BrathaMatu</td></tr>
        <tr><td style="padding:28px">
          <h1 style="font-size:20px;margin:0 0 16px">You’ve been invited</h1>
          <p style="font-size:16px">You have been invited to join <strong>{{ $organisationName }}</strong> with the <strong>{{ $roleName }}</strong> role on BrathaMatu.</p>
          <p style="color:#4b5563">Create your password and accept the invitation using the secure link below.</p>
          <p style="margin:24px 0"><a href="{{ $acceptUrl }}" style="display:inline-block;background:#0f766e;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:bold">Accept invitation</a></p>
          <p style="color:#6b7280;font-size:13px">This invitation expires {{ $expiresAt }} and can only be used once.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
