<?php
// templates/otp_form.php

// This template has access to $config, $leadEventId, $phoneCapi, $errorMessage, etc.
$otpJsPath = __DIR__ . '/../public/assets/js/otp-form.js';
$otpJsVer = file_exists($otpJsPath) ? filemtime($otpJsPath) : '1.0';
?>

<?php if (!empty($pixelId) && !empty($leadEventId)): ?>
<script>
    if (typeof fbq === 'function') {
        fbq('track', 'Lead',
            { ph: '<?php echo htmlspecialchars($phoneCapi, ENT_QUOTES, 'UTF-8'); ?>' },
            { eventID: '<?php echo htmlspecialchars($leadEventId, ENT_QUOTES, 'UTF-8'); ?>' }
        );
    }
</script>
<?php endif; ?>

<section class="form-section">
    <form action="" method="post">
        <span class="form-title">දුරකතන අංකය තහවුරු කිරීම</span>
        <span class="form-text">
            <?php if (empty($errorMessage) && $showSmsLink): ?>
                PIN අංකය නැද්ද? පහල බොත්තම ඔබලා සෙන්ඩ් කරන්න
            <?php else: ?>
                ඔබගේ දුරකතන අංකය වෙත ලැබුනු PIN අංකය ඇතුළත් කරන්න
            <?php endif; ?>
        </span>

        <?php if (empty($errorMessage) && $showSmsLink): ?>
        <a id="smsLink" href="sms:<?php echo $smsNumber; ?>?body=REG%20<?php echo $smsKeyword; ?>">
            මේක ඔබන්න
        </a>
        <?php endif; ?>

        <div id="otpError" class="alert alert-danger" style="<?php echo isset($errorMessage) ? '' : 'display:none;'; ?>">
            <?php echo isset($errorMessage) ? htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') : ''; ?>
        </div>

        <?php if (!$showSmsLink): ?>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="text" inputmode="numeric"
            pattern="\d*" placeholder="Enter the pin" minlength="6" maxlength="6"
            name="otp" id="otpInput" required autocomplete="one-time-code">
        <input type="submit" value="Verify">
        <?php endif; ?>
        <span>නැවත PIN අංකය ඉල්ලීමට <a href="/">මෙතන ඔබන්න.</a></span>
    </form>
</section>

<script src="assets/js/otp-form.js?v=<?php echo $otpJsVer; ?>" defer></script>