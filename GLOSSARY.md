# OTP Chat

An SMS-OTP subscription landing page: a visitor submits a Sri Lankan mobile number, receives a one-time PIN through the carrier's OTP provider, and confirms it to subscribe. Conversions are reported to Meta and Google.

## Language

**OTP flow**:
The two-step lifecycle of one OTP for one visitor: the request step (a phone number is submitted and an OTP is sent, or SMS fallback is entered) and the verification step (the OTP is submitted and the visitor is registered, the OTP is renewed, or an app fallback happens). The thank-you page is outside it.
_Avoid_: registration flow, OTP lifecycle

**App**:
One application registered on a platform, with its own subscriber base, reached at its own base URL. A platform has an ordered list of apps, and a number can be registered on several of them.
_Avoid_: endpoint, gateway, base URL, URL, service

**App fallback**:
Trying the next app of the same platform when the current one cannot issue an OTP, cannot verify the submitted one, or the visitor's verification attempts run out. Within one such round an app is tried once; an app that gave a refusal is skipped, and after a failed verification so is the app that issued the OTP, for that round only. An app that failed for any other reason can be tried again in a later round. A token renewal is not an app fallback: it starts again from the platform's first app.
_Avoid_: multi-URL fallback, URL rotation, retry

**Refusal**:
An app's answer that the number can never subscribe through it: already registered (E1351) or blacklisted (E1342). An app that gave one is skipped by every later app fallback and token renewal of the OTP flow; any other failure is temporary, and the app can be asked again.
_Avoid_: failed app, blocked app

**OTP token**:
The platform's handle for one issued OTP, held for the visitor until it is verified, renewed or abandoned.
_Avoid_: verification token, reference

**Token reuse**:
Answering a repeated request for the same number with the OTP token already held, while it is still fresh, instead of asking the provider again.

**Token renewal**:
Obtaining a fresh OTP for the same number after the platform reports the current one expired, or unknown while SMS fallback is unavailable; the platform's apps are tried from the first again, skipping those that gave a refusal.
_Avoid_: auto-renew, re-request

**SMS fallback**:
The mode in which the OTP input is replaced by a link that sends the registration SMS. Entered when the platform reports a temporary failure issuing an OTP, when the number's request limit is reached, after three wrong OTPs, or when the OTP is unknown; available only when an SMS number and keyword are configured.
_Avoid_: SMS link mode, sms link

**Platform**:
The OTP provider family a number is routed to — ideamart, mspace or bdapps — chosen from the number's prefix.
_Avoid_: telco, provider (on its own)

**Carrier**:
The mobile network a number belongs to — Dialog, Hutch, Airtel, Mobitel, Robi — chosen from the prefix; it sets the conversion value.
_Avoid_: operator, network

**Visitor**:
One anonymous person across sessions, identified by a long-lived visitor ID.
_Avoid_: user, customer, session
