<!DOCTYPE html>
<html>
<body style="margin:0; padding:32px; background:#0B0E14; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
    <div style="max-width:480px; margin:0 auto; background:#11151E; border:1px solid #232A38; border-radius:14px; padding:28px;">
        <p style="margin:0 0 4px; font-size:11px; letter-spacing:.08em; text-transform:uppercase; color:#9D8CF5; font-family: monospace;">Revue</p>
        <h1 style="margin:0 0 16px; font-size:20px; color:#F4F6FB;">Ta revue est prête</h1>

        @if ($pulseExcerpt)
            <p style="margin:0 0 20px; font-size:14px; line-height:1.6; color:#B8C0D4;">{{ $pulseExcerpt }}</p>
        @endif

        <a href="{{ route('review.index') }}" style="display:inline-block; background:#53D6E8; color:#0B0E14; font-weight:600; font-size:14px; padding:10px 18px; border-radius:9px; text-decoration:none;">
            Ouvrir la revue
        </a>
    </div>
</body>
</html>
