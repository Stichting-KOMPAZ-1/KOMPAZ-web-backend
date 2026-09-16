<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $title }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f7f9; color:#302227; font-family:Arial, Helvetica, sans-serif;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:transparent;">
        {{ $preheader }}
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; background-color:#f4f7f9;">
        <tr>
            <td align="center" style="padding:96px 20px;">
                <table role="presentation" width="520" cellspacing="0" cellpadding="0" border="0" style="width:100%; max-width:520px; background-color:#ffffff; border-radius:12px;">
                    <tr>
                        <td align="center" style="padding:32px 36px 28px;">
                            <img src="https://stichtingkompaz.nl/wp-content/uploads/2024/04/Logo_mark_blauw.png" width="48" height="48" alt="KOMPAZ" style="display:block; width:48px; height:48px; margin:0 auto 18px; border:0;">

                            <h1 style="margin:0 0 10px; color:#302227; font-size:22px; line-height:28px; font-weight:700;">
                                {{ $heading }}
                            </h1>

                            <p style="max-width:410px; margin:0 auto 24px; color:#302227; font-size:16px; line-height:21px;">
                                {{ $body }}
                            </p>

                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto;">
                                <tr>
                                    <td align="center" bgcolor="#b45d7b" style="border-radius:7px;">
                                        <a href="{{ $link }}" style="display:inline-block; padding:14px 24px; color:#ffffff; font-size:16px; line-height:20px; font-weight:700; text-decoration:none; border-radius:7px;">
                                            {{ $action }}
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            @if ($validity !== '')
                                <p style="margin:12px 0 0; color:#302227; font-size:12px; line-height:16px;">
                                    {{ $validity }}
                                </p>
                            @endif

                            <p style="margin:28px 0 0; color:#302227; font-size:12px; line-height:16px;">
                                Stichting KOMPAZ
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
