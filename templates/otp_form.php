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

<section class="form-section">
    <form action="" method="post" 
        <?php if (!empty($gaMeasurementId)): ?>
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
            <?php if (!empty($gaMeasurementId)): ?>
                onclick="gtag('event', 'sms_link_click', {
                    'event_category': 'engagement',
                    'event_label': 'sms_fallback_link'
                });"
            <?php endif; ?>
            >
            මේක ඔබන්න
        </a>
        <?php endif; ?>

        <div id="otpError" class="alert alert-danger" style="<?php echo isset($errorMessage) ? '' : 'display:none;'; ?>">
            <?php echo isset($errorMessage) ? htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') : ''; ?>
        </div>
        <?php if (!empty($errorMessage) && !empty($gaMeasurementId)): ?>
        <script>
            gtag('event', 'form_error', {
                'event_category': 'form',
                'event_label': 'otp_form_error',
                'message': '<?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>'
            });
        </script>
        <?php endif; ?>

        <?php if (!$showSmsLink): ?>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="text" inputmode="numeric"
            pattern="\d*" placeholder="Enter the pin" minlength="6" maxlength="6"
            name="otp" id="otpInput" required autocomplete="one-time-code">
        <input type="submit" value="Verify">
        <?php endif; ?>
        <span>නැවත PIN අංකය ඉල්ලීමට <a href="/">මෙතන ඔබන්න.</a></span>
    </form>
    <script>
        document.querySelector('form').addEventListener('submit', function(e) {
            e.preventDefault();

            const form = this;
            const submitBtn = form.querySelector('input[type="submit"]');
            const errorDiv = document.getElementById('otpError');
            const otpInput = document.getElementById('otpInput');

            if (!submitBtn) return;

            // UI Loading State
            errorDiv.style.display = "none";
            submitBtn.disabled = true;
            const originalBtnValue = submitBtn.value;
            submitBtn.value = "Verifying...";

            const formData = new FormData(form);

            const redirectMessages = ["Session expired", "Registration failed", "Security check failed", "An error occurred"];
            
            fetch('', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                if (data.status === 'success' && data.redirect) {
                    window.location.href = data.redirect;
                } else if (data.message && redirectMessages.some(str => data.message.includes(str)) && data.redirect) {
                    // Show Error
                    errorDiv.innerText = data.message || "An error occurred. Please try again.";
                    errorDiv.style.display = "block";

                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 2000);
                } else {
                    // Show Error
                    errorDiv.innerText = data.message || "An error occurred. Please try again.";
                    errorDiv.style.display = "block";
                    
                    // If showSmsLink is returned, hide form and show SMS fallback (requires page refresh or further DOM manipulation)
                    if (data.showSmsLink) {
                        window.location.reload(); // Simplest way to show SMS link if triggered
                    } else {
                        submitBtn.disabled = false;
                        submitBtn.value = originalBtnValue;
                        otpInput.value = '';
                        otpInput.focus();
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                errorDiv.innerText = "සබඳතාවල දෝෂයක්. කරුණාකර නැවත උත්සාහ කරන්න.";
                errorDiv.style.display = "block";
                submitBtn.disabled = false;
                submitBtn.value = originalBtnValue;
            });
        });
    </script>
</section>