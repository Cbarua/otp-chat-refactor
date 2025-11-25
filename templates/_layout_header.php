<?php
// templates/_layout_header.php

// This file is included by the controller's render method,
// so it has access to all variables in the $data array.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <link rel="shortcut icon" href="assets/images/icons/two-hearts.png" type="image/x-icon">
    
    <link rel="stylesheet" href="assets/css/blog.css">
    
    <?php if ($pixelId): ?>
        <script>
            ! function(f, b, e, v, n, t, s) {
                if (f.fbq) return;
                n = f.fbq = function() {
                    n.callMethod ?
                        n.callMethod.apply(n, arguments) : n.queue.push(arguments)
                };
                if (!f._fbq) f._fbq = n;
                n.push = n;
                n.loaded = !0;
                n.version = '2.0';
                n.queue = [];
                t = b.createElement(e);
                t.async = !0;
                t.src = v;
                s = b.getElementsByTagName(e)[0];
                s.parentNode.insertBefore(t, s)
            }(window, document, 'script',
                'https://connect.facebook.net/en_US/fbevents.js');
            
            // Initialize the Pixel
            fbq('init', '<?php echo htmlspecialchars($pixelId, ENT_QUOTES, 'UTF-8'); ?>');
        </script>
        <noscript><img height="1" width="1" style="display:none"
                src="https://www.facebook.com/tr?id=<?php echo htmlspecialchars($pixelId, ENT_QUOTES, 'UTF-8'); ?>&ev=PageView&noscript=1" /></noscript>
        <title>Welcome</title>
    <?php endif; ?>
</head>
<body>
    <div class="box-container">