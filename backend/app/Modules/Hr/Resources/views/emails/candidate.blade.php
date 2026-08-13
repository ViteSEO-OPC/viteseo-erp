{{-- The HTML shell around a body HR wrote in the compose modal.

     Deliberately plain: inline styles only, one table-free centred column, no images and no
     external stylesheet. Mail clients strip <style> blocks and rewrite layouts unpredictably,
     and a hiring email has nothing to gain from a design that half of them will mangle.

     $bodyHtml has already been through HtmlSanitizer, which is why it is echoed raw. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $email->subject }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f5f5f5;">
    <div style="max-width:600px; margin:0 auto; padding:24px 20px; background-color:#ffffff;
                font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
                font-size:15px; line-height:1.6; color:#1f2328;">
        {!! $bodyHtml !!}
    </div>
</body>
</html>
