@php
    $code = $code ?? null;
    $expiry = $expiry ?? null;
    $details = $details ?? [];
    $buttonUrl = $buttonUrl ?? null;
    $buttonLabel = $buttonLabel ?? null;
    $note = $note ?? null;
    $logoPath = public_path('images/optimized/lance-apple-touch-180.png');
    $logoUrl = isset($message) && is_file($logoPath) ? $message->embed($logoPath) : asset('images/optimized/lance-apple-touch-180.png');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $heading }} | FurniCraft</title>
    <style>
        :root { color-scheme: light dark; supported-color-schemes: light dark; }
        @media only screen and (max-width: 600px) {
            .email-outer { padding: 8px 6px !important; }
            .email-padding { padding-left: 18px !important; padding-right: 18px !important; }
            .email-title { font-size: 20px !important; }
            .email-brand { font-size: 18px !important; }
        }
        @media (prefers-color-scheme: dark) {
            .email-background { background-color: #282825 !important; }
            .email-card, .email-header, .email-footer { background-color: #30312d !important; border-color: #484a41 !important; }
            .email-title, .email-brand, .email-value { color: #d6d3ca !important; }
            .email-muted, .email-copy { color: #bcb9ae !important; }
            .email-code-box, .email-message { background-color: #30312d !important; border-color: #484a41 !important; }
            .email-code { color: #e0d1b4 !important; }
            .email-note { background-color: #30312d !important; color: #c7ccbc !important; }
            .email-detail { border-color: #484a41 !important; }
        }
        [data-ogsc] .email-background { background-color: #282825 !important; }
        [data-ogsc] .email-card, [data-ogsc] .email-header, [data-ogsc] .email-footer { background-color: #30312d !important; border-color: #484a41 !important; }
        [data-ogsc] .email-title, [data-ogsc] .email-brand, [data-ogsc] .email-value { color: #d6d3ca !important; }
        [data-ogsc] .email-muted, [data-ogsc] .email-copy { color: #bcb9ae !important; }
        [data-ogsc] .email-code-box, [data-ogsc] .email-message { background-color: #30312d !important; border-color: #484a41 !important; }
        [data-ogsc] .email-code { color: #e0d1b4 !important; }
        [data-ogsc] .email-note { background-color: #30312d !important; color: #c7ccbc !important; }
        [data-ogsc] .email-detail { border-color: #484a41 !important; }
    </style>
</head>
<body class="email-background" style="margin:0;padding:0;background-color:#f6f4ef;color:#33251f;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;text-size-adjust:100%;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{{ $intro }}</div>
    <table class="email-background" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f6f4ef;">
        <tr>
            <td class="email-outer" align="center" style="padding:20px 12px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" class="email-card" style="max-width:600px;background-color:#fffefa;border:0;border-radius:0;">
                    <tr>
                        <td class="email-padding email-header" style="padding:18px 24px 12px;background-color:#fffefa;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td valign="middle" width="36"><img src="{{ $logoUrl }}" alt="Lance Furniture Shop logo" width="36" height="36" style="display:block;width:36px;height:36px;border:0;border-radius:50%;"></td>
                                    <td class="email-brand" style="padding-left:10px;color:#493526;font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:600;">FurniCraft<span class="email-muted" style="display:block;margin-top:4px;color:#6c6256;font-size:11px;font-weight:normal;letter-spacing:0;">Lance Furniture Shop</span></td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td class="email-padding" style="padding:12px 24px 14px;">
                            <h1 class="email-title" style="margin:0;color:#2e211c;font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:600;line-height:1.35;">{{ $heading }}</h1>
                            <p class="email-copy" style="margin:10px 0 0;color:#5e564c;font-size:14px;line-height:1.6;">{{ $intro }}</p>
                        </td>
                    </tr>
                    @if($code)
                    <tr>
                        <td class="email-padding" style="padding:0 24px 12px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" class="email-code-box" style="background-color:#fffefa;border:0;">
                                <tr>
                                    <td align="left" style="padding:2px 0 10px;">
                                        <p class="email-muted" style="margin:0 0 7px;color:#6c6256;font-size:12px;font-weight:normal;letter-spacing:0;line-height:1.5;">{{ ucfirst(strtolower($codeLabel)) }}</p>
                                        <p class="email-code" style="margin:0;color:#513225;font-family:'Courier New',monospace;font-size:28px;font-weight:600;letter-spacing:4px;white-space:nowrap;line-height:1.3;">{{ $code }}</p>
                                        @if($expiry)<p class="email-muted" style="margin:7px 0 0;color:#6c6256;font-size:12px;line-height:1.5;">{{ $expiry }}</p>@endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @endif
                    @if(count($details))
                    <tr>
                        <td class="email-padding" style="padding:0 24px 12px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" class="email-detail" style="border:0;">
                                @foreach($details as $detail)
                                <tr>
                                    <td class="email-detail" style="padding:9px 0;border-bottom:{{ $loop->last ? '0' : '1px solid #e5ddd2' }};">
                                        <p class="email-muted" style="margin:0 0 3px;color:#6c6256;font-size:12px;font-weight:normal;letter-spacing:0;line-height:1.4;">{{ $detail['label'] }}</p>
                                        <p class="email-value" style="margin:0;color:#33251f;font-size:14px;font-weight:normal;line-height:1.5;overflow-wrap:anywhere;word-break:break-word;">{{ $detail['value'] }}</p>
                                    </td>
                                </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                    @endif
                    @if(!empty($bodyText))
                    <tr><td class="email-padding" style="padding:0 24px 12px;"><div class="email-message" style="padding:12px;background-color:#f0ece3;border-radius:8px;color:#33251f;font-size:14px;line-height:1.7;white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word;">{{ $bodyText }}</div></td></tr>
                    @endif
                    @if($buttonUrl && $buttonLabel)
                    <tr>
                        <td class="email-padding" align="left" style="padding:4px 24px 12px;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td align="center" bgcolor="#513225" style="border-radius:8px;">
                                        <a href="{{ $buttonUrl }}" style="display:inline-block;padding:13px 24px;border:1px solid #513225;border-radius:8px;background-color:#513225;color:#ffffff;font-size:14px;font-weight:700;line-height:1.3;text-decoration:none;">{{ $buttonLabel }}</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @endif
                    @if($note)
                    <tr>
                        <td class="email-padding" style="padding:0 24px 16px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" class="email-note" style="background-color:#fffefa;">
                                <tr><td class="email-note" style="padding:0;color:#6c6256;font-size:12px;line-height:1.6;">{{ $note }}</td></tr>
                            </table>
                        </td>
                    </tr>
                    @endif
                    <tr>
                        <td class="email-padding email-footer" style="padding:14px 24px;border-top:1px solid #e5ddd2;background-color:#fffefa;">
                            <p class="email-muted" style="margin:0;color:#6c6256;font-size:11px;line-height:1.6;">{{ $footerNote ?? 'Sent by FurniCraft for Lance Furniture Shop.' }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
