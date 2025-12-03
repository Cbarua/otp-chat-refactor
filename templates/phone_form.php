<?php
// templates/phone_form.php

// This template has access to $config, $pageViewEventId, $testEventCode, $errorMessage, etc.
?>

<section class="img-section">
    <div class="img-container">
        <img src="<?php echo htmlspecialchars($config['content']['img_url'], ENT_QUOTES, 'UTF-8'); ?>"
            alt="<?php echo htmlspecialchars($config['content']['img_alt'], ENT_QUOTES, 'UTF-8'); ?>">
    </div>
</section>

<section class="form-section">
    <form id="leadForm" action="" method="post"> <span class="form-text">ඔබගේ දුරකතන අංකය පහතින් ඇතුළත් කරන්න</span>

        <?php if (isset($errorMessage)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if (isset($alreadyRegistered)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($alreadyRegistered, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div id="phoneError" class="alert alert-danger" style="display:none;">
            වලංගු ජංගම දුරකථන අංකය ඇතුළත් කරන්න. උදා : 0772221234
        </div>

        <input type="hidden" id="fbp" name="fbp" value="">
        <input type="hidden" id="fbc" name="fbc" value="">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

        <input type="tel" id="mobile" name="mobile" placeholder="0700000000" maxlength="10" minlength="9" required autocomplete="tel">
        <input type="submit" value="Register">

    </form>
</section>

<span id="charging"><?php echo htmlspecialchars($config['content']['charge_text'], ENT_QUOTES, 'UTF-8'); ?></span>
</div>
<script>
    document.getElementById("leadForm").addEventListener("submit", function (e) {
        const phoneInput = document.getElementById('mobile');
        const errorDiv = document.getElementById('phoneError');
        const phone = phoneInput.value.trim();

        // Only accept 07XXXXXXXX (Sri Lankan mobile numbers)
        const regex = /^07\d{8}$/;

        if (!regex.test(phone)) {
            e.preventDefault(); // stop submission
            errorDiv.style.display = "block";
            phoneInput.focus();
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

        // If valid → hide error, allow submit
        errorDiv.style.display = "none";
        return true;
    });
</script>