# User Stories

**Last Updated:** October 31, 2026

What the app does today, told from the side of the people who deal with it. It is not a backlog: every story describes behaviour that is already built. Terms like *OTP flow*, *app fallback*, *refusal*, *token renewal* and *SMS fallback* mean exactly what `GLOSSARY.md` says. The code-level detail (classes, status codes, session keys, the AJAX/HTML table) is in `docs/registration_flow.md`; each section links to its part.

**Roles**

- **Visitor**: someone who lands from an ad, submits a mobile number and subscribes with a PIN (the OTP).
- **Marketer**: the person running the Meta and Google ads, who needs every step of the funnel counted once, with the right value.
- **Operator**: the person running the sites on the server: configuration, logs and deploys.

**Rules that hold for every visitor story**

- Only Sri Lankan numbers are accepted. The Bangladesh setup (Robi and Airtel on bdapps) exists in the carrier configuration, but the number check only takes Sri Lankan numbers, so a Bangladeshi number is rejected as invalid.
- Everything works with and without JavaScript. With JavaScript the forms send in place and show the message on the page; without it the page reloads with the same message. On success the visitor moves on at once; on an error that moves them to another page, the message shows first (3 s on the landing page, 2 s on the PIN page).
- The pages are in Sinhala; the error messages quoted below are in English, as the visitor sees them.

---

## Visitor: subscribing

Detail: `registration_flow.md` sections 1–5.

### V1. Enter my number

As a visitor, I want to enter my mobile number on the landing page, so that I get a PIN to subscribe.

- The page shows the offer image, the charge text and a number field.
- A number written `07XXXXXXXX` or `7XXXXXXXX` is accepted when its prefix belongs to a carrier: Mobitel (70, 71), Hutch (72, 78), Dialog (74, 76, 77) or Airtel (75).
- Anything else (wrong length, 073, 079, a Bangladeshi number) shows "Invalid phone number. Example: 0771234567" and I stay on the page.
- The prefix decides the platform: Mobitel numbers go to mspace, the others to ideamart.

### V2. Get my PIN

As a visitor, I want a PIN sent to my phone after I submit my number, so that I can prove the number is mine.

- The platform's apps are asked in order until one sends the PIN (app fallback); I don't see which app it was.
- Once a PIN is sent I land on the PIN page.

### V3. Not get a second PIN by submitting twice

As a visitor, I want to go back to the PIN page without a new SMS if I submit the same number again soon, so that the PIN I already have still works.

- Submitting the same number within 5 minutes of the PIN being sent takes me straight to the PIN page; no new PIN is sent (token reuse).
- After 5 minutes, or with a different number, a new PIN is requested.

### V4. Enter my PIN and subscribe

As a visitor, I want to type the 6-digit PIN and be subscribed, so that I get the service.

- A correct PIN that the platform confirms as a subscription takes me to the thank-you page, which tells me an SMS will confirm the registration.
- Opening the thank-you page without having just subscribed sends me to the landing page; so does refreshing it after the first view.
- Opening the PIN page without having submitted a number sends me to the landing page.

### V5. Be told I'm already subscribed

As a visitor who already subscribed, I want to be told so instead of getting a PIN, so that I don't wonder why nothing arrives.

- When no app sends a PIN and the last one asked says the number is already registered, I stay on the landing page with a green "You are already registered!".

---

## Visitor: when something goes wrong

Detail: `registration_flow.md` sections 2 and 4, and the response table.

### V6. Fix a wrong PIN

As a visitor, I want to be told when my PIN is wrong, so that I can type it again.

- Fewer or more than 6 digits, or letters: "Invalid OTP. Must be 6 digits."
- A wrong PIN: "Invalid OTP. Please enter the correct OTP." and I stay on the PIN page.
- After 3 wrong PINs, if SMS fallback is available, the PIN field is replaced by the SMS link (V9).

### V7. Get a new PIN when mine expired

As a visitor whose PIN expired, I want a new one sent automatically, so that I don't have to start over.

- When the platform says the PIN expired, a new one is sent (token renewal) and I see "Your OTP expired. A new OTP has been sent to your phone." on the PIN page.
- The same happens when the platform no longer knows the PIN and SMS fallback is not available.
- Apps that gave a refusal earlier for my number are not asked again.
- If no app sends a new PIN, I see "An error occurred. Please try again later." and go back to the landing page.

### V8. Get a PIN from another app when this one gives up

As a visitor, I want a fresh PIN from another app when the one that sent mine can't verify it, so that I can still subscribe.

- This happens when the app reports too many attempts for that PIN, gives an unexpected answer or doesn't answer, or when I try more than 5 PINs within 10 minutes.
- The next apps are asked (app fallback), skipping the one that just failed and any that gave a refusal. I see "Please try again with the new OTP sent to your phone." and stay on the PIN page.
- If no app sends one, I see "An error occurred. Please try again later." ("Too many attempts. Please try again later." when the 10-minute limit caused it) and stay on the PIN page.

### V9. Subscribe by SMS when the PIN route fails

As a visitor, I want a one-tap way to subscribe by SMS when the PIN can't be used, so that I still get the service.

- The PIN field is replaced by a button that opens my SMS app with `REG <keyword>` addressed to the service number (SMS fallback).
- It appears when the platform has a temporary problem sending a PIN, when my number hits its PIN request limit (V10), after 3 wrong PINs, or when the platform doesn't know my PIN.
- It is only offered when the operator has set both the SMS number and the keyword (O2). Without them I see the matching error message instead.

### V10. Be told I asked for too many PINs

As a visitor whose number hit the platform's PIN request limit, I want to know when I can try again, so that I don't keep retrying.

- The number is blocked for 60 minutes. I get the SMS link (V9) if it is available, otherwise "Maximum number of OTP requests reached for <number>. Please try again in 60 minutes".
- Submitting the number again during that hour shows the same message with the minutes left, even when SMS fallback is available.

### V11. Be slowed down when submitting too often

As a visitor, I want a clear message when I submit numbers too fast, so that I know to wait.

- More than 5 number submissions in a minute from the same connection: "Too many attempts. Please try again later." and I stay on the landing page.

### V12. Recover from an expired page or session

As a visitor who left a page open too long, I want to be sent back to a working page, so that I can start again.

- A form whose security check fails shows "Security check failed. Please try again." and reloads the page I was on (the landing page sends me back to itself).
- Submitting a PIN after my session was lost shows "Session expired" and sends me to the landing page.

### V13. Be told when the platform won't subscribe me

As a visitor, I want a clear message when the platform can't send a PIN or confirms the PIN but doesn't activate the subscription, so that I know to try again.

- No app can send a PIN (for example the number is blacklisted, or no app answers): "An error occurred. Please try again later." on the landing page.
- The PIN is right but the subscription isn't active: "Registration failed. Please try again." on the PIN page.

---

## Marketer: conversion tracking

Detail: `registration_flow.md` sections 1, 3 and 5, and the `FacebookCapiService` notes.

### M1. See every step of the funnel in Meta

As a marketer, I want each step of the funnel reported to Meta, so that I can optimise the ads on real conversions.

- PageView on the landing page, the PIN page and the thank-you page.
- Lead the first time the PIN page opens after a PIN is sent, or after the visitor enters SMS fallback straight from the landing page.
- CompleteRegistration on the thank-you page.
- Each event is sent both from the browser (Pixel) and from the server (Conversions API) with the same event ID, so Meta counts it once.

### M2. Get a value per carrier

As a marketer, I want Lead and CompleteRegistration to carry a value that depends on the carrier, so that Meta can bid for the better-paying carriers.

- Values are in USD, drawn at random within a range on each request: Dialog and Airtel 0.015–0.020, Hutch 0.010–0.015, Mobitel 0–0.010.

### M3. Match conversions to ad clicks

As a marketer, I want the server events to carry what Meta needs to match a visitor, so that conversions are credited to the right ad.

- Every server event carries the visitor's IP, browser, long-lived visitor ID and the Meta click and browser cookies.
- Once the visitor has submitted a number, events also carry the phone number and the country.

### M4. Never count the same conversion twice

As a marketer, I want repeats and noise kept out of the numbers, so that the reports stay honest.

- Lead is sent once each time a submitted number reaches the PIN page with a new PIN or SMS fallback; reloading the PIN page, a token reuse (V3), a token renewal or an app fallback sends no new Lead.
- The thank-you page can't be refreshed into a second CompleteRegistration (V4).
- A page reloaded only to show an error sends no new PageView; the Pixel reuses the earlier event ID.
- Uptime monitors (HEAD requests) send no events and leave no visit-log entry.

### M5. See the funnel in Google Analytics

As a marketer, I want the pages and registrations in Google Analytics, so that I can compare with Meta.

- With a GA4 measurement ID set, every page loads the Google tag, and the thank-you page sends a `generate_lead` event.

### M6. Test the events before going live

As a marketer, I want to send server events to Meta's Test Events view, so that I can check them without polluting live data.

- With a test event code set, every server event is tagged with it.

---

## Operator: running the sites

Detail: `README.md`, `.env.sample` and `CLAUDE.md`.

### O1. Choose the apps for each platform

As an operator, I want to list each platform's apps in the order to try them, so that I control the app fallback.

- One JSON list of base URLs per platform (`IDEAMART_URLS`, `MSPACE_URLS`, `BDAPPS_URLS`), tried first to last.

### O2. Turn SMS fallback on or off

As an operator, I want to turn SMS fallback on per site, so that visitors get the SMS route only where it is set up.

- It is on only when both `SMS_NUMBER` and `SMS_KEYWORD` are set.

### O3. Turn tracking on or off

As an operator, I want each tracker switched by its own setting, so that a site without an ad account still works.

- `PIXEL_ID` turns on the Pixel, `FBCAPI_TOKEN` the server events, `GA_MEASUREMENT_ID` Google Analytics, `TEST_EVENT_CODE` the Meta test tag.
- Without `FBCAPI_TOKEN` no server events are sent, but visits are still logged.

### O4. Change the landing content

As an operator, I want to set each site's image and charge text, so that one codebase serves several offers.

- `IMG_URL`, `IMG_ALT` and `CHARGE_TEXT`.

### O5. Find out what happened to a visitor

As an operator, I want logs I can follow per visitor, so that I can explain a failed or missing subscription.

- The app log and the CAPI log are JSON, rotated daily and kept 10 days, and every line carries the visitor ID.
- Visits (with the phone once submitted) and rate limits are kept in one SQLite file (`DB_PATH`).
- PHP errors go to `ERROR_LOG_PATH`; an unexpected crash shows the visitor a plain error page and is logged.

### O6. Deploy by pushing

As an operator, I want a push to deploy every site, so that all instances run the same code.

- A push to `main` or `test` resets every instance on the server to that branch, installs production dependencies and reloads the web server. A push to `test` therefore ships to production.
- Visitors in the middle of subscribing keep their place across a deploy.

### O7. Keep test switches out of production

As an operator, I want the test-only switches to be impossible to trigger on a live site, so that nobody can steer it with cookies.

- The cookies the acceptance tests use are honoured only when the server process runs with `TEST_HOOKS=1`, which production never sets.
