<?php
// templates/otp_form.php

// This template has access to $config, $leadEventId, $phoneCapi, $errorMessage, etc.
?>

<?php if (!empty($pixelId) && !empty($leadEventId)): ?>
<script>
    fbq('track', 'Lead',
        { ph: '<?php echo htmlspecialchars($phoneCapi, ENT_QUOTES, 'UTF-8'); ?>' },
        { eventID: '<?php echo htmlspecialchars($leadEventId, ENT_QUOTES, 'UTF-8'); ?>' }
    );
</script>
<?php endif; ?>


<section class="img-section">
    <div class="img-container">
        <img src="<?php echo htmlspecialchars($config['content']['img_url'], ENT_QUOTES, 'UTF-8'); ?>"
            alt="<?php echo htmlspecialchars($config['content']['img_alt'], ENT_QUOTES, 'UTF-8'); ?>">
    </div>
</section>

<section class="form-section">
    <form action="" method="post" 
        <?php if (!empty($config['google']['ga_measurement_id'])): ?>
            onsubmit="gtag('event', 'submit_otp', {'event_category': 'engagement', 'event_label': 'otp_submitted'});"
        <?php endif; ?>>
        <span class="form-title">දුරකතන අංකය තහවුරු කිරීම</span>
        <span class="form-text">
            <?php if (empty($errorMessage) && $showSmsLink): ?>
                PIN අංකය නැද්ද? පහල බොත්තම ඔබලා සෙන්ඩ් කරන්න
            <?php else: ?>
                ඔබගේ දුරකතන අංකය වෙත ලැබුනු PIN අංකය ඇතුළත් කරන්න
            <?php endif; ?>
        </span>

        <?php if (empty($errorMessage) && $showSmsLink): ?>
        <a id="smsLink" href="sms:<?php echo $smsNumber; ?>?body=REG%20<?php echo $smsKeyword; ?>" 
            <?php if (!empty($config['google']['ga_measurement_id'])): ?>
                onclick="gtag('event', 'sms_link_click', {
                    'event_category': 'engagement',
                    'event_label': 'sms_fallback_link'
                });"
            <?php endif; ?>
            >
            මේක ඔබන්න
        </a>
        <?php endif; ?>

        <?php if (isset($errorMessage)): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php if (!empty($config['google']['ga_measurement_id'])): ?>
        <script>
            gtag('event', 'form_error', {
                'event_category': 'form',
                'event_label': 'otp_form_error',
                'message': '<?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>'
            });
        </script>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (!$showSmsLink): ?>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="text" inputmode="numeric"
            pattern="\d*" placeholder="Enter the pin" minlength="6" maxlength="6"
            name="otp" required autocomplete="one-time-code">
        <input type="submit" value="Verify">
        <?php endif; ?>
        <span>නැවත PIN අංකය ඉල්ලීමට <a href="/">මෙතන ඔබන්න.</a></span>
    </form>
    <script>
        document.querySelector('form').addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('input[type="submit"]');
            if (submitBtn) {
                // Short delay to allow the form submission payload to be constructed
                setTimeout(() => {
                    submitBtn.disabled = true;
                    submitBtn.value = "Verifying...";
                }, 0);
            }
        });
    </script>
</section>