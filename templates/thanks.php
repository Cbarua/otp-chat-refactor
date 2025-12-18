<?php
// templates/thanks.php

// This template has access to $config, $regId, $phoneCapi, $eventData, etc.
?>

<?php if (!empty($pixelId) && !empty($regId)): ?>
<script>
    fbq('track', 'CompleteRegistration',
        <?php echo $eventData; ?>,
        { eventID: '<?php echo htmlspecialchars($regId, ENT_QUOTES, 'UTF-8'); ?>' }
    );
</script>
<?php endif; ?>

<?php if (!empty($config['google']['ga_measurement_id'])): ?>
<script>
    gtag('event', 'generate_lead', {
        'event_category': 'conversion',
        'event_label': 'registration_complete'
    });
</script>
<?php endif; ?>

<section class="align-self-md-center">
	<div class="alert alert-success">ඔබගේ ලියාපදිංචිය තහවුරු කිරීමට ඔබගේ දුරකතන අංකයට කෙටි පණිවිඩයක් මඟින් දැනුම් දෙනු ලැබේ</div>
</section>