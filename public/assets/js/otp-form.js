document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('.form-section form');
    if (!form) return;

    const otpInput = document.getElementById('otpInput');
    const submitBtn = form.querySelector('input[type="submit"]');
    const errorDiv = document.getElementById('otpError');
    const smsLink = document.getElementById('smsLink');

    // Helper for Google Analytics event tracking
    function trackGa(eventName, params) {
        if (typeof window.gtag === 'function') {
            window.gtag('event', eventName, params);
        }
    }

    // Fire initial server-side error event if error is present on page render
    if (errorDiv && errorDiv.style.display !== 'none' && errorDiv.innerText.trim() !== '') {
        trackGa('form_error', {
            event_category: 'form',
            event_label: 'otp_form_error',
            message: errorDiv.innerText.trim()
        });
    }

    // Track SMS Link click
    if (smsLink) {
        smsLink.addEventListener('click', function () {
            trackGa('sms_link_click', {
                event_category: 'engagement',
                event_label: 'sms_fallback_link'
            });
        });
    }

    // Auto-submit form when 6 digits are entered
    if (otpInput) {
        otpInput.addEventListener('input', function () {
            if (this.value.trim().length === 6) {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                }
            }
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        if (!submitBtn) return;

        trackGa('submit_otp', {
            event_category: 'engagement',
            event_label: 'otp_submitted'
        });

        // UI Loading State
        errorDiv.style.display = "none";
        submitBtn.disabled = true;
        const originalBtnValue = submitBtn.value;
        submitBtn.value = "Verifying...";

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
            if (data.status === 'success') {
                window.location.href = data.redirect;
                return;
            }

            // Show Error
            errorDiv.innerText = data.message || "An error occurred. Please try again.";
            errorDiv.style.display = "block";

            // The server decides where to go next ('otp' reloads, e.g. to show the SMS link); null stays.
            if (data.redirect) {
                setTimeout(() => {
                    window.location.href = data.redirect;
                }, 2000);
                return;
            }
            submitBtn.disabled = false;
            submitBtn.value = originalBtnValue;
            if (otpInput) {
                otpInput.value = '';
                otpInput.focus();
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
});
