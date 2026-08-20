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
    <meta name="description" content="<?php echo htmlspecialchars($config['content']['meta_description'] ?? 'OTP verification and lead registration portal', ENT_QUOTES, 'UTF-8'); ?>">
    
    <link rel="shortcut icon" 
        href="<?php echo htmlspecialchars($_ENV['ICON_PATH'] ?? 'assets/images/icons/two-hearts.png', ENT_QUOTES, 'UTF-8') ?>" 
        type="image/x-icon">

    <!-- Preconnect / DNS-prefetch resource hints -->
    <?php if (!empty($pixelId)): ?>
        <link rel="preconnect" href="https://connect.facebook.net" crossorigin>
        <link rel="dns-prefetch" href="https://connect.facebook.net">
    <?php endif; ?>
    <?php if (!empty($gaMeasurementId)): ?>
        <link rel="preconnect" href="https://www.googletagmanager.com" crossorigin>
        <link rel="dns-prefetch" href="https://www.googletagmanager.com">
    <?php endif; ?>

    <!-- Preload Critical Web Fonts -->
    <link rel="preload" href="assets/fonts/montserrat-regular.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="assets/fonts/noto-sans-sinhala.woff2" as="font" type="font/woff2" crossorigin>
    
    <?php
        $blogCssPath = __DIR__ . '/../public/assets/css/blog.css';
        $desktopCssPath = __DIR__ . '/../public/assets/css/desktop.css';
        $blogCssVer = file_exists($blogCssPath) ? filemtime($blogCssPath) : '1.0';
        $desktopCssVer = file_exists($desktopCssPath) ? filemtime($desktopCssPath) : '1.0';
    ?>
    <!-- Modular Stylesheets: Mobile core and Conditional Desktop styles -->
    <link rel="stylesheet" href="assets/css/blog.css?v=<?php echo $blogCssVer; ?>">
    <link rel="stylesheet" href="assets/css/desktop.css?v=<?php echo $desktopCssVer; ?>" media="(min-width: 800px)">
    
    <?php if (!empty($pixelId)): ?>
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
            fbq('init', '<?php echo htmlspecialchars($pixelId, ENT_QUOTES, 'UTF-8'); ?>', {
                external_id: '<?php echo htmlspecialchars($externalId, ENT_QUOTES, 'UTF-8'); ?>'
                <?php if (!empty($phoneCapi)): ?>, 
                    country: '<?php echo htmlspecialchars($country, ENT_QUOTES, 'UTF-8'); ?>',
                    ph: '<?php echo htmlspecialchars($phoneCapi, ENT_QUOTES, 'UTF-8'); ?>'
                <?php endif; ?>
            });
        </script>
        <noscript>
            <img height="1" width="1" style="display:none"
                src="https://www.facebook.com/tr?id=<?php echo htmlspecialchars($pixelId, ENT_QUOTES, 'UTF-8'); ?>&ev=PageView&noscript=1" />
        </noscript>

        <?php if (!empty($pageViewEventId)): ?>
            <script>
                // Fire the PageView event for the /otp page
                fbq('track', 'PageView',
                    {},
                    { eventID: '<?php echo htmlspecialchars($pageViewEventId, ENT_QUOTES, 'UTF-8'); ?>' }
                );
            </script>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($gaMeasurementId)): ?>
        <!-- Google tag (gtag.js) -->
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo htmlspecialchars($gaMeasurementId, ENT_QUOTES, 'UTF-8'); ?>"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());

            gtag('config', '<?php echo htmlspecialchars($gaMeasurementId, ENT_QUOTES, 'UTF-8'); ?>');
        </script>
    <?php endif; ?>
    <?php 
        $imgUrl = $config['content']['img_url'] ?? '';
        $webpUrl = preg_replace('/\.(png|jpg|jpeg)$/i', '.webp', $imgUrl);
    ?>
    <?php if (!empty($webpUrl)): ?>
        <link rel="preload" as="image" href="<?php echo htmlspecialchars($webpUrl, ENT_QUOTES, 'UTF-8'); ?>" type="image/webp" fetchpriority="high">
    <?php endif; ?>
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
</head>
<body>
    <main class="box-container">
        <section class="img-section">
            <div class="img-container">
                <picture>
                    <?php if (!empty($webpUrl) && $webpUrl !== $imgUrl): ?>
                        <source srcset="<?php echo htmlspecialchars($webpUrl, ENT_QUOTES, 'UTF-8'); ?>" type="image/webp">
                    <?php endif; ?>
                    <img src="<?php echo htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8'); ?>"
                        alt="<?php echo htmlspecialchars($config['content']['img_alt'], ENT_QUOTES, 'UTF-8'); ?>"
                        fetchpriority="high"
                        decoding="async"
                        width="350"
                        height="350">
                </picture>
            </div>
        </section>