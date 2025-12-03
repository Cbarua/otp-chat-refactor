<?php
// templates/otp_form.php

// This template has access to $config, $leadEventId, $phoneCapi, $errorMessage, etc.
?>

<?php if ($pixelId): ?>
    <script>
        // Re-init pixel with Advanced Matching
        fbq('init', '<?php echo htmlspecialchars($pixelId, ENT_QUOTES, 'UTF-8'); ?>', {
            external_id: '<?php echo htmlspecialchars($externalId, ENT_QUOTES, 'UTF-8'); ?>',
            country: '<?php echo htmlspecialchars($country, ENT_QUOTES, 'UTF-8'); ?>'
                <?php if (!empty($phoneCapi)): ?>, ph: '<?php echo htmlspecialchars($phoneCapi, ENT_QUOTES, 'UTF-8'); ?>'<?php endif; ?>
        });

        <?php if (!empty($pageViewEventId)): ?>
            // Fire the PageView event for the /otp page
            fbq('track', 'PageView',
                {},
                { eventID: '<?php echo htmlspecialchars($pageViewEventId, ENT_QUOTES, 'UTF-8'); ?>' }
            );
        <?php endif; ?>

        <?php if (!empty($leadEventId)): ?>
            // 2. Fire the Lead event (ONLY RUNS ONCE)
            // This shares the eventID with the CAPI event
            fbq('track', 'Lead',
                { ph: '<?php echo htmlspecialchars($phoneCapi, ENT_QUOTES, 'UTF-8'); ?>' },
                { eventID: '<?php echo htmlspecialchars($leadEventId, ENT_QUOTES, 'UTF-8'); ?>' }
            );
        <?php endif; ?>
    </script>
<?php endif; ?>


<section class="img-section">
    <div class="img-container">
        <img src="<?php echo htmlspecialchars($config['content']['img_url'], ENT_QUOTES, 'UTF-8'); ?>"
            alt="<?php echo htmlspecialchars($config['content']['img_alt'], ENT_QUOTES, 'UTF-8'); ?>">
    </div>
</section>

<section class="form-section">
    <form action="" method="post">
        <span class="form-title">දුරකතන අංකය තහවුරු කිරීම</span>
        <span class="form-text">ඔබගේ දුරකතන අංකය වෙත ලැබුනු PIN අංකය ඇතුළත් කරන්න</span>

        <?php if (isset($errorMessage)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="text" inputmode="numeric" pattern="\d*" placeholder="Enter the pin" name="otp" required autocomplete="one-time-code">
        <input type="submit" value="Verify">

        <span>නැවත PIN අංකය ඉල්ලීමට <a href="/">මෙතන ඔබන්න.</a></span>
    </form>
</section>