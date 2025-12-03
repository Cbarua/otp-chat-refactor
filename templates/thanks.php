<?php
// templates/thanks.php

// This template has access to $config, $regId, $phoneCapi, $eventData, etc.
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
        // Fire the PageView event for the /thanks page
        fbq('track', 'PageView',
            {},
            { eventID: '<?php echo htmlspecialchars($pageViewEventId, ENT_QUOTES, 'UTF-8'); ?>' }
        );
    <?php endif; ?>

    <?php if (!empty($regId)): ?>
        // Fire the CompleteRegistration event, sharing eventID with CAPI
        fbq('track', 'CompleteRegistration', 
            <?php echo $eventData; // JSON data, e.g., {'currency': 'USD', 'value': 0.02} ?>,
            { eventID: '<?php echo htmlspecialchars($regId, ENT_QUOTES, 'UTF-8'); ?>' }
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

<section class="align-self-md-center">
	<div class="alert alert-success">ඔබගේ ලියාපදිංචිය තහවුරු කිරීමට ඔබගේ දුරකතන අංකයට කෙටි පණිවිඩයක් මඟින් දැනුම් දෙනු ලැබේ</div>
</section>