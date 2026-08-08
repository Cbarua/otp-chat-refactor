<?php
// templates/phone_form.php

// This template has access to $config, $pageViewEventId, $testEventCode, $errorMessage, etc.
?>

<section class="form-section">
    <form id="leadForm" action="" method="post">
        <?php if (isset($_ENV['FORM_TITLE'])): ?>
            <span class="form-title"><?php echo htmlspecialchars($_ENV['FORM_TITLE'], ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>

        <label for="mobile" class="form-text">ඔබගේ දුරකතන අංකය පහතින් ඇතුළත් කරන්න</label>

        <div id="phoneError" class="alert alert-danger" style="<?php echo isset($errorMessage) ? '' : 'display:none;'; ?>">
            <?php echo isset($errorMessage) ? htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') : ''; ?>
            <?php if (!empty($errorMessage) && !empty($gaMeasurementId)): ?>
                <script>
                    if (typeof gtag === 'function') {
                        gtag('event', 'form_error', {
                            'event_category': 'form',
                            'event_label': 'phone_form_error',
                            'message': <?php echo json_encode($errorMessage); ?>
                        });
                    }
                </script>
            <?php endif; ?>
        </div>

        <div id="alreadyRegistered" class="alert alert-success" style="<?php echo !empty($alreadyRegistered) ? '' : 'display:none;'; ?>">
            <?php echo !empty($alreadyRegistered) ? htmlspecialchars($alreadyRegistered, ENT_QUOTES, 'UTF-8') : ''; ?>
            <?php if (!empty($alreadyRegistered) && !empty($gaMeasurementId)): ?>
                <script>
                    if (typeof gtag === 'function') {
                        gtag('event', 'form_error', {
                            'event_category': 'form',
                            'event_label': 'already_registered',
                        });
                    }
                </script>
            <?php endif; ?>
        </div>

        <input type="hidden" id="fbp" name="fbp" value="">
        <input type="hidden" id="fbc" name="fbc" value="">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

        <input type="tel" id="mobile" name="mobile" placeholder="0700000000" maxlength="10" minlength="9" required autocomplete="tel">
        <input type="submit" value="Register">

    </form>
</section>

<span id="charging"><?php echo htmlspecialchars($config['content']['charge_text'], ENT_QUOTES, 'UTF-8'); ?></span>

<script>
    document.getElementById("leadForm").addEventListener("submit", function (e) {
        e.preventDefault(); // Intercept form submission

        const form = this;
        const phoneInput = document.getElementById('mobile');
        const errorDiv = document.getElementById('phoneError');
        const alreadyRegisteredDiv = document.getElementById('alreadyRegistered');
        const submitBtn = form.querySelector('input[type="submit"]');
        const phone = phoneInput.value.trim();

        // Client-side Validation (Accept Sri Lankan mobile numbers starting with 07 or 7)
        const regex = /^(?:07|7)\d{8}$/;
        if (!regex.test(phone)) {
            errorDiv.innerText = "වලංගු ජංගම දුරකථන අංකයක් ඇතුළත් කරන්න. උදා : 0772221234";
            errorDiv.style.display = "block";
            phoneInput.focus();
            
            <?php if (!empty($gaMeasurementId)): ?>
            if (typeof gtag === 'function') {
                gtag('event', 'form_error', {
                    'event_category': 'form',
                    'event_label': 'client_side_phone_error',
                    'message': 'Invalid phone format'
                });
            }
            <?php endif; ?>
            return false;
        }

        // Helper to read a cookie value by name
        function getCookie(name) {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2) return parts.pop().split(';').shift();
            return '';
        }

        // Populate hidden fields with cookie values before submitting
        document.getElementById('fbp').value = getCookie('_fbp');
        document.getElementById('fbc').value = getCookie('_fbc');

        // If valid → hide error and previous registration success messages, allow submit
        errorDiv.style.display = "none";
        alreadyRegisteredDiv.style.display = "none";
        submitBtn.disabled = true;
        const originalBtnValue = submitBtn.value;
        submitBtn.value = "Please wait...";

        <?php if (!empty($gaMeasurementId)): ?>
        if (typeof gtag === 'function') {
            gtag('event', 'begin_registration', {
                'event_category': 'engagement',
                'event_label': 'phone_submitted_ajax'
            });
        }
        <?php endif; ?>

        // Perform AJAX Call
        const formData = new FormData(form);
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
            } else {
                // Show Error
                if (data.message === 'user already registered') {
                    alreadyRegisteredDiv.innerText = "You are already registered!";
                    alreadyRegisteredDiv.style.display = "block";
                }
                else{
                    errorDiv.innerText = data.message || "An error occurred. Please try again.";
                    errorDiv.style.display = "block";
                }
                submitBtn.disabled = false;
                submitBtn.value = originalBtnValue;

                <?php if (!empty($gaMeasurementId)): ?>
                if (typeof gtag === 'function') {
                    if (data.message === 'user already registered') {
                        gtag('event', 'form_error', {
                            'event_category': 'form',
                            'event_label': 'already_registered',
                        });
                    } else {
                        gtag('event', 'form_error', {
                            'event_category': 'form',
                            'event_label': 'phone_form_error',
                            'message': data.message || 'Unknown error'
                        });
                    }
                }
                <?php endif; ?>

                if (data.showSmsLink === true) {
                    setTimeout(() => {
                        window.location.href = data.redirect ?? 'otp';
                    }, 3000);
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