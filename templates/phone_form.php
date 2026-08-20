<?php
// templates/phone_form.php

// This template has access to $config, $pageViewEventId, $testEventCode, $errorMessage, etc.
$phoneJsPath = __DIR__ . '/../public/assets/js/phone-form.js';
$phoneJsVer = file_exists($phoneJsPath) ? filemtime($phoneJsPath) : '1.0';
?>

<section class="form-section">
    <form id="leadForm" action="" method="post">
        <?php if (isset($_ENV['FORM_TITLE'])): ?>
            <span class="form-title"><?php echo htmlspecialchars($_ENV['FORM_TITLE'], ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>

        <label for="mobile" class="form-text">ඔබගේ දුරකතන අංකය පහතින් ඇතුළත් කරන්න</label>

        <div id="phoneError" class="alert alert-danger" style="<?php echo isset($errorMessage) ? '' : 'display:none;'; ?>">
            <?php echo isset($errorMessage) ? htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') : ''; ?>
        </div>

        <div id="alreadyRegistered" class="alert alert-success" style="<?php echo !empty($alreadyRegistered) ? '' : 'display:none;'; ?>">
            <?php echo !empty($alreadyRegistered) ? htmlspecialchars($alreadyRegistered, ENT_QUOTES, 'UTF-8') : ''; ?>
        </div>

        <input type="hidden" id="fbp" name="fbp" value="">
        <input type="hidden" id="fbc" name="fbc" value="">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

        <input type="tel" id="mobile" name="mobile" inputmode="tel" pattern="[0-9]*" placeholder="0700000000" maxlength="10" minlength="9" required autocomplete="tel">
        <input type="submit" value="Register">

    </form>
</section>

<span id="charging"><?php echo htmlspecialchars($config['content']['charge_text'], ENT_QUOTES, 'UTF-8'); ?></span>

<script src="assets/js/phone-form.js?v=<?php echo $phoneJsVer; ?>" defer></script>