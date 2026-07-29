<!DOCTYPE html>
<html>
<body style="margin:0; padding:32px; background:#08090C; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
    <div style="max-width:480px; margin:0 auto; background:#0F1116; border:1px solid #2F333C; border-radius:12px; padding:28px;">
        <p style="margin:0 0 4px; font-size:11px; letter-spacing:.08em; text-transform:uppercase; color:#A78BFA; font-family: monospace;">Revue</p>
        <h1 style="margin:0 0 16px; font-size:20px; color:#E8EAF0;">Ta revue est prête</h1>

        @if ($pulseExcerpt)
            <p style="margin:0 0 20px; font-size:14px; line-height:1.6; color:#C4C9D6;">{{ $pulseExcerpt }}</p>
        @endif

        <a href="{{ route('review.index') }}" style="display:inline-block; background:#6E8BFF; color:#06070A; font-weight:600; font-size:14px; padding:10px 18px; border-radius:7px; text-decoration:none;">
            Ouvrir la revue
        </a>
    </div>
</body>
</html>
