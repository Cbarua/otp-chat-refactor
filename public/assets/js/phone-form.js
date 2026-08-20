document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('leadForm');
    if (!form) return;

    const phoneInput = document.getElementById('mobile');
    const errorDiv = document.getElementById('phoneError');
    const alreadyRegisteredDiv = document.getElementById('alreadyRegistered');
    const submitBtn = form.querySelector('input[type="submit"]');

    // Helper for Google Analytics event tracking
    function trackGa(eventName, params) {
        if (typeof window.gtag === 'function') {
            window.gtag('event', eventName, params);
        }
    }

    // Fire initial server-side error events if present on page render
    if (errorDiv && errorDiv.style.display !== 'none' && errorDiv.innerText.trim() !== '') {
        trackGa('form_error', {
            event_category: 'form',
            event_label: 'phone_form_error',
            message: errorDiv.innerText.trim()
        });
    }

    if (alreadyRegisteredDiv && alreadyRegisteredDiv.style.display !== 'none' && alreadyRegisteredDiv.innerText.trim() !== '') {
        trackGa('form_error', {
            event_category: 'form',
            event_label: 'already_registered'
        });
    }

    // Helper to read a cookie value by name
    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
        return '';
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault(); // Intercept form submission

        const phone = phoneInput.value.trim();

        // Client-side Validation (Accept Sri Lankan mobile numbers starting with 07 or 7)
        const regex = /^(?:07|7)\d{8}$/;
        if (!regex.test(phone)) {
            errorDiv.innerText = "වලංගු ජංගම දුරකථන අංකයක් ඇතුළත් කරන්න. උදා : 0772221234";
            errorDiv.style.display = "block";
            phoneInput.focus();

            trackGa('form_error', {
                event_category: 'form',
                event_label: 'client_side_phone_error',
                message: 'Invalid phone format'
            });
            return false;
        }

        // Populate hidden fields with cookie values before submitting
        const fbpInput = document.getElementById('fbp');
        const fbcInput = document.getElementById('fbc');
        if (fbpInput) fbpInput.value = getCookie('_fbp');
        if (fbcInput) fbcInput.value = getCookie('_fbc');

        // If valid -> hide error and previous registration success messages, allow submit
        errorDiv.style.display = "none";
        alreadyRegisteredDiv.style.display = "none";
        submitBtn.disabled = true;
        const originalBtnValue = submitBtn.value;
        submitBtn.value = "Please wait...";

        trackGa('begin_registration', {
            event_category: 'engagement',
            event_label: 'phone_submitted_ajax'
        });

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
                    trackGa('form_error', {
                        event_category: 'form',
                        event_label: 'already_registered'
                    });
                } else {
                    errorDiv.innerText = data.message || "An error occurred. Please try again.";
                    errorDiv.style.display = "block";
                    trackGa('form_error', {
                        event_category: 'form',
                        event_label: 'phone_form_error',
                        message: data.message || 'Unknown error'
                    });
                }
                submitBtn.disabled = false;
                submitBtn.value = originalBtnValue;

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
});
