# Provider Status Codes (Ideamart, mSpace, bdapps)

**Last Updated:** October 29, 2026

This note compares the OTP `statusCode` / `statusDetail` values and the `subscriptionStatus` values that the three platforms document: Ideamart (Dialog, Hutch, Airtel; LK), mSpace (Mobitel; LK) and bdapps (Robi; BD). It also lists what the middle layer (`telco-platform-laravel`, which every app now runs on) adds or changes. Every claim carries a source tag; the tags are defined in [Sources](#6-sources). Strings in backticks are copied byte for byte, typos included.

---

## 1. Short answer

**The OTP codes the platforms document mean the same thing on all three, with one exception: E1853.**

- E1850, E1851 and E1852 have the same code and the same `statusDetail` on all three platforms, typos included: `Invalid OTP`, `OTP request has being expired`, `Maximum number of OTP attempts had reached` [IO] [M otp/verify] [B otp/verify].
- The mSpace and bdapps OTP sections are word for word the same (E1301, E1850–E1857). Only the sample values differ [M otp/request, otp/verify] [B otp/request, otp/verify].
- Ideamart's official docs cover less. For OTP they document only E1351, E1850, E1851 and E1852 [IO]. Its response-code list has no E185x code at all [IC].

Divergences:

1. **E1853 conflicts.** On mSpace and bdapps it is an OTP *request* error: `Maximum number of OTP requests reached for [msisdn/sender ID]` [M otp/request] [B otp/request]. The Ideamart-as-a-Skill repo makes it an OTP *verify* error: `No active OTP request found for this reference number` [K08:176] [K13:776]. The official Ideamart pages don't list E1853 [IO] [IC]. The text in our production logs (`Maximum number of OTP requests reached for [app/tel:N]`) has the mSpace/bdapps shape.
2. **E1351** (`user already registered`) is documented by Ideamart only [IO]. mSpace and bdapps don't list it, but mSpace tenants do return it in production [G8:28, G8:30].
3. **E1301** means the same on all three ("application not allowed"), but mSpace and bdapps add `for operator unknown.` [IC] [M otp/request] [B otp/request].
4. **E1603 and E1342** mean the same on all three, but each platform words them differently, and the mSpace and bdapps OTP sections don't list them (see the table).
5. **E1300 and E1338** are documented by no platform for OTP. E1300 appears only in mSpace's CaaS direct-debit section (`Unknown/unclassified error`) [M caas/otpGeneration]. E1338 appears nowhere.
6. **The same code can mean something else on another API of the same platform.** mSpace's CaaS charging notification uses E1855, E1856 and E1857 for other things than its subscription OTP endpoints do [M caas/chargingNotification].

`subscriptionStatus` on OTP verify: all three platforms give only `REGISTERED` as the example and list no other values [IO] [M otp/verify] [B otp/verify]. No provider page mentions `INITIAL CHARGING PENDING` in either spelling. The space-versus-underscore split comes from the middle layer's production notes, not from the docs (section 3).

---

## 2. Status codes compared

"Not documented" means the code is missing from every section of that platform's page. "Not on OTP" means the page lists the code, but only for other APIs; the text from those APIs is quoted. The mSpace and bdapps descriptions end without a full stop unless shown.

| Code | Ideamart | mSpace | bdapps | Same meaning on all 3? |
|---|---|---|---|---|
| S1000 | OTP request and verify samples: `Success` [IO "Response - Success"] | Request: `Successfully send OTP challenge to [msisdn/sender ID]`. Verify: `Successfully validated OTP for [msisdn/sender ID]`. Both samples: `Success` [M otp/request, otp/verify] | Same as mSpace [B otp/request, otp/verify] | Yes |
| E1351 | OTP request: `user already registered` (lower-case u) [IO "Already Registered"]. General list: `User already registered` [IC] | Not documented. Returned in production on mSpace tenants, wording not recorded [G8:28, G8:30] | Not documented | Unknown: documented by Ideamart only |
| E1853 | Not documented [IO] [IC]. Skill repo: `No active OTP request found for this reference number`, on verify [K08:176] [K13:776] | OTP request: `Maximum number of OTP requests reached for [msisdn/sender ID]` [M otp/request] | Same as mSpace [B otp/request] | **No: conflict** (request limit vs. no active request) |
| E1850 | `Invalid OTP` [IO "Wront OTP"] | OTP verify: `Invalid OTP` [M otp/verify] | `Invalid OTP` [B otp/verify] | Yes, same text |
| E1851 | `OTP request has being expired` [IO "OTP Expired"]. Skill repo rewrites it as `OTP request has expired` [K08:174] | `OTP request has being expired` [M otp/verify] | Same [B otp/verify] | Yes, same text |
| E1852 | `Maximum number of OTP attempts had reached` [IO "OTP Attempt exceeded"]. Skill repo: `Maximum number of OTP attempts reached` [K08:175] | `Maximum number of OTP attempts had reached` [M otp/verify] | Same [B otp/verify] | Yes, same text |
| E1854 | Not documented [IO] [IC] [K08]. Returned in production on an Ideamart tenant (c-tpjob-12) [G8:29] | OTP verify: `Could not find OTP` [M otp/verify] | Same [B otp/verify] | Probably: documented on 2 of 3, seen on Ideamart |
| E1855 | Not documented | OTP verify: `Invalid Reference Number` [M otp/verify] | Same [B otp/verify] | Unknown for Ideamart |
| E1856 | Not documented | OTP request: `Invalid Request` [M otp/request] | Same [B otp/request] | Unknown for Ideamart |
| E1857 | Not documented | OTP request and verify: `Internal Server Error Occur` [M otp/request, otp/verify] | Same [B otp/request, otp/verify] | Unknown for Ideamart |
| E1301 | `Requested ApplicationID is not allowed within the System` [IC] | OTP request and verify: `Requested ApplicationID is not allowed within the System for operator unknown.` [M otp/request, otp/verify] | Same as mSpace [B otp/request, otp/verify] | Yes; wording differs |
| E1603 | `Temporary System Error occurred while delivering your request.` (with full stop) [IC]. Skill repo drops the full stop [K08:168] | Not on OTP. `Temporary system error occurred while delivering your request` (sms/send); `Temporary System Error occurred while delivering your request` (subscription/getSubscriberChargingInfo); `Temporary system error` (caas/otpGeneration, i.e. direct debit) [M] | Not on OTP. `Temporary system error occurred while delivering your request` (sms/send); `Temporary System Error occurred while delivering your request` (sms/send per-destination responses) [B] | Yes; wording and case differ |
| E1342 | `Sorry, Your phone number is blacklisted to use this application {0}` [IC]. Skill repo writes `your` [K08:128] | Not on OTP. sms/send: `MSISDN is black listed. Not authorized to use the application <application_name>.`; caas/otpGeneration: `Your phone number is blacklisted to use this application` [M] | Not on OTP. sms/send: `MSISDN is black listed. Not authorized to use the application <application_name>.` [B] | Yes; wording differs (`blacklisted` vs `black listed`) |
| E1300 | Not documented | caas/otpGeneration (direct debit) only: `Unknown/unclassified error` [M] | Not documented | Unknown. Our production text `Default Error has occurred.` appears in no doc |
| E1338 | Not documented | Not documented | Not documented | Unknown. Middle-layer notes call it "user confirmation required (double opt-in, bdapps and Ideamart)" [G8:38] |

Endpoints, for reference: Ideamart uses `/subscription/otp/request` and `/subscription/otp/verify` on `api.ideamart.io` or `api.dialog.lk` [IO]. mSpace uses `https://api.mspace.lk/otp/request` and `/otp/verify` [M otp/request, otp/verify]. bdapps uses `/otp/request` and `/otp/verify` on `https://developer.bdapps.com` [B].

---

## 3. `subscriptionStatus` values

### Ideamart

| API | Field | Documented values | Source |
|---|---|---|---|
| OTP verify | `subscriptionStatus` | `REGISTERED` (success sample only). Skill repo: "REGISTERED on success." | [IO "Response - Success"] [K13:765] |
| Register / Unregister (`/subscription/send`) | `subscriptionStatus` | `REGISTERED`, `UNREGISTERED`, `PENDING`. "PENDING CHARGE = subscriber is not charged yet." | [IS "Register / Unregister response parameters"] [K04:94] |
| Subscription status (`/subscription/getStatus`) | `subscriptionStatus` | `REGISTERED / UNREGISTERED / PENDING / CHARGE` | [IS "Subscription status response parameters"] [K04:153] |
| Subscription notification (inbound) | `status` | `REGISTERED` (sample only). Skill repo: one of `REGISTERED`, `UNREGISTERED` | [IS "Subscription Notification Service"] [K13:1315] |

Ideamart's docs write multi-word states with spaces (`PENDING CHARGE`).

### mSpace

| API | Field | Documented values | Source |
|---|---|---|---|
| OTP verify (`/otp/verify`) | `subscriptionStatus` | `REGISTERED` (example). Type is given as `ENUM`, described as "Subscription status of the user"; no values listed | [M otp/verify] |
| User subscription (`/subscription/send`) | `subscriptionStatus` | "eg:UNREGISTERED,REGISTERED" (sample shows `UNREGISTERED.` with a stray full stop) | [M subscription/userSubscription] |
| Subscriber status (`/subscription/getStatus`) | `subscriptionStatus` | `INITIAL`, `REG_PENDING`, `TRIAL`, `REGISTERED`, `UNREGISTERED`, `TEMPORARY_BLOCKED` | [M subscription/subscriberStatus] |
| Charging info (`/subscription/getSubscriberChargingInfo`) | `subscriptionStatus` | The same six, each defined. `REG_PENDING`: "Subscription request is made and charging request is also sent but charging is not yet success". `TEMPORARY_BLOCKED`: registered once, then recurring charging failed | [M subscription/getSubscriberChargingInfo] |
| Subscriber list (`/subscription/getSubscriberList`) | `subscribers.subscriptionStatus` | "Eg: TRIAL, REGISTERED and TEMPORARY_BLOCKED" | [M subscription/getSubscriberList] |
| Subscriber notification (`/subscription/notify`) | `status` | "eg:UNREGISTERED,REGISTERED" (sample shows `REGISTERED.`) | [M subscription/notify] |

mSpace's docs write multi-word states with underscores (`REG_PENDING`, `TEMPORARY_BLOCKED`).

### bdapps

| API | Field | Documented values | Source |
|---|---|---|---|
| OTP verify (`/otp/verify`) | `subscriptionStatus` | `REGISTERED` (example). Type `ENUM`, no values listed | [B otp/verify] |
| User subscription (`/subscription/send`) | `subscriptionStatus` | "eg:UNREGISTERED,REGISTERED" | [B subscription/userSubscription] |
| Subscriber status (`/subscription/getStatus`) | `subscriptionStatus` | "eg:UNREGISTERED,REGISTERED" | [B subscription/subscriberStatus] |
| Subscriber notification (`/subscription/notify`) | `status` | "eg:UNREGISTERED,REGISTERED" | [B subscription/notify] |

bdapps documents no pending or blocked state.

### Seen in production (middle-layer notes, not provider docs)

| Value | Platform | Source |
|---|---|---|
| `INITIAL CHARGING PENDING` | Ideamart | [G8:101] [G8:120]; Ideamart legacy DB `mechat_users` holds 1,033 rows [G6:83]; `Models/User.php:22` |
| `INITIAL_CHARGING_PENDING` | mSpace | [G8:101] [G8:120]; mSpace legacy DB `chatapp_users` holds 757 rows [G6:46]; `Models/User.php:24` |
| `REG_PENDING` | mSpace | [G8:105]; `Models/User.php:26` |
| `INITIAL` | mSpace (getStatus) | `Models/User.php:28-29` |
| `TEMPORARY BLOCKED` | Ideamart (legacy DB) | [G6:83] |
| `TEMPORARY_BLOCKED` | mSpace | [G8:120]; [PD:24]; also in mSpace docs above |
| `PENDING CONFIRMATION`, `PENDING_CONFIRMATION`, `PENDING` | Ideamart (Dialog) and bdapps, with E1338, on subscribe / getStatus | [G8:109]; `Drivers/AbstractTelcoDriver.php:27-29, 220, 292` |

These notes don't say which API returned each value (OTP verify, getStatus or the notification). The middle layer passes the verify value to our page unchanged (section 4), so any spelling a platform sends on verify reaches us as is. The user's report that mSpace sends `INITIAL_CHARGING_PENDING` and Ideamart sends `INITIAL CHARGING PENDING` matches these notes and the spelling style of each platform's docs, but no provider page confirms it.

---

## 4. What the middle layer adds or changes

Paths are under `/Users/chinmoy/projects/personal/telco-platform-laravel/app/`. Our page reaches the middle layer at `{base}/getOtp.php` and `{base}/verifyOtp.php` (`../routes/tenant.php:50-51`), which go through `Http/Controllers/CentralWebhookController.php:143-157` to `Domains/Shared/Controllers/OtpController.php:25-47`. That controller returns `OtpService`'s array as JSON with HTTP 200.

**Codes it creates.** Neither code appears on any provider page.

- `E1000`
  - Request validation failed: `Domains/Shared/Requests/Api/RequestOtpRequest.php:45-51` (first validator message), `VerifyOtpRequest.php:26-32` (`Reference number and OTP are required.`). Both return HTTP 200.
  - OTP request returned S1000 with no `referenceNo`: `Missing fields in telco response` (`Domains/Shared/Services/OtpService.php:75-86`).
  - Verify returned S1000 with no `subscriberId` or `subscriptionStatus`: same text (`OtpService.php:149-157`).
  - The provider could not be reached (HTTP 503) or answered with an HTTP error (the provider's HTTP status) (`Domains/Shared/Drivers/AbstractTelcoDriver.php:382-396`).
- `E500`
  - The provider's reply had no `statusCode`, or another exception was thrown (`AbstractTelcoDriver.php:377-379, 397-400`, HTTP 500).
  - Database failures: `Internal Error` (`OtpService.php:94-100`, `174-181`). The first one also puts `'http_code' => 500` in the body, but the response is still HTTP 200.
  - Unknown `referenceNo`: `Reference number not found.` (`OtpService.php:111-115`).
- Transport errors are rendered by `TelcoException` as `{status: "error", statusCode, statusDetail}` with the exception's HTTP code (`Domains/Shared/Exceptions/TelcoException.php:32-38`).

**Shape changes to provider replies.**

- OTP request error: returns only `status: "error"`, `statusCode` and `statusDetail`; the rest of the provider reply is dropped. A missing `statusDetail` becomes `Failed to send OTP` (`OtpService.php:47-73`).
- OTP request success: `status: "success"` plus the provider reply unchanged (`OtpService.php:103`).
- **OTP verify error: `status` is set to the provider's `statusDetail`, not `"error"`**, followed by the whole provider reply (`OtpService.php:128-143`). The comment there says the middle layer's frontend expects it.
- OTP verify success: `status: "success"` plus the provider reply. `subscriptionStatus` is not checked against any list or normalised; any non-empty value passes (`OtpService.php:146-183`).

**Prose matching it does.** It matches `statusDetail`, not codes:

- `user already registered` (exact match), `Maximum number of OTP` (substring), `operator unknown` (substring), `blacklisted` (case-insensitive substring) on OTP request (`OtpService.php:53-63`). The constants are at `AbstractTelcoDriver.php:47-51`.
- `blacklisted` (case-insensitive) on verify (`OtpService.php:132`).
- The middle layer's own web sign-up matches `Invalid OTP`, `Could not find OTP` and `OTP request has being expired` exactly (`Domains/Shared/Controllers/UserAuthController.php:262-267`; constants at `AbstractTelcoDriver.php:53-57`).

**`_ALT` constants and spelling variants.** These are used by subscribe / getStatus, not by the OTP path:

- `STATUS_PENDING_CONFIRMATION = 'PENDING CONFIRMATION'` and `_ALT = 'PENDING_CONFIRMATION'` (`AbstractTelcoDriver.php:27-29`). `subscribe()` and `getSubscriptionStatus()` also accept a bare `'PENDING'` (`AbstractTelcoDriver.php:220, 292`).
- `STATUS_TEMPORARY_BLOCKED = 'TEMPORARY BLOCKED'` and `_ALT = 'TEMPORARY_BLOCKED'` (`AbstractTelcoDriver.php:31-33`).
- On the model: `INITIAL CHARGING PENDING` / `_ALT` `INITIAL_CHARGING_PENDING`, plus `REG_PENDING` and `INITIAL` (`Domains/Shared/Models/User.php:22-29`). `pendingStatuses()` treats all four as pending (`User.php:57-65`).
- `ERR_PENDING_CONFIRMATION = 'E1338'`, `ERR_BLACKLISTED = 'E1342'` and `ERR_INVALID_REQUEST = 'E1856'` (`AbstractTelcoDriver.php:37-41`). `E1856` is the middle layer's own code for a bad USSD request, with the text `Invalid Request` (`Http/Controllers/CentralWebhookController.php:26-28`).

**Per-platform code.** No driver special-cases a status code or `statusDetail`. All status handling sits in the shared `AbstractTelcoDriver` and `OtpService`. The drivers differ only in:

- base URL: `api.dialog.lk` (`Drivers/IdeamartDriver.php:11`), `api.mspace.lk` (`Drivers/MspaceDriver.php:17`), `developer.bdapps.com` (`Drivers/BdappsDriver.php:11`);
- OTP paths: Ideamart uses `/subscription/otp/request|verify` (`IdeamartDriver.php:14-22`), while mSpace and bdapps inherit `/otp/request|verify` (`AbstractTelcoDriver.php:104-112`). This matches the docs;
- mSpace `getSubscriberList`, which reads `statusDetails` or `statusDetail` (`MspaceDriver.php:76`).

---

## 5. Gaps and conflicts

- **Ideamart's official docs are thin on OTP.** The OTP page lists only E1351, E1850, E1851 and E1852 [IO]. The response-code page lists no E185x code [IC].
- **The Ideamart-as-a-Skill repo disagrees with the official pages it cites.** It says its table is "Reproduced from" the official response-code page [K08:84]. But it adds E1850–E1853, which that page doesn't have, and it rewrites wording:
  - E1851: `has expired` instead of `has being expired`;
  - E1852: `attempts reached` instead of `attempts had reached`;
  - E1342: `your` instead of `Your`;
  - E1603: no full stop.

  For exact strings, the official pages and our production logs agree with each other, not with the skill repo.
- **E1853** means "OTP request limit" on mSpace/bdapps and "no active OTP request" in the skill repo (section 1). Production shows the request-limit wording, but the logs don't say which platform sent it.
- **mSpace and bdapps put the verify error codes in the `subscriberId` field description**, not in `statusDetail` [M otp/verify] [B otp/verify]. This looks like a doc slip.
- **Codes seen in production but missing from the OTP docs:**
  - E1351 on mSpace [G8:28];
  - E1854 on Ideamart [G8:29];
  - E1603 and E1300 on mSpace [G8:28];
  - E1342 on Ideamart and bdapps [G8:27, G8:29];
  - E1338 on Ideamart and bdapps [G8:27, G8:34].

  The digest counts errors on every channel (OTP, subscription API, SMS), not just OTP [G8:38].
- **Codes depend on the API.**
  - mSpace's CaaS charging notification uses E1855 `Invalid OTP Reference Number`, E1856 `OTP Not Found` and E1857 `Invalid Reference Number` [M caas/chargingNotification]. Its subscription OTP endpoints use E1856 `Invalid Request` and E1857 `Internal Server Error Occur`.
  - The middle layer reads E1351 on an SMS send as "charging pending" (`Domains/Shared/Enums/SmsDeliveryFailureReason.php:78`), not "already registered".
- **`black listed` vs `blacklisted`.** mSpace and bdapps word E1342 on SMS as `MSISDN is black listed…` [M sms/send] [B sms/send]. The middle layer detects blacklisting in OTP replies with the substring `blacklisted` only (`OtpService.php:62, 132`), so that wording would be missed there. The SMS path also checks the code E1342 (`SmsDeliveryFailureReason.php:55-56`). Our production OTP text uses `blacklisted`.
- **`subscriptionStatus` on verify is undocumented** beyond `REGISTERED` on all three platforms. None of the docs names `INITIAL CHARGING PENDING` / `INITIAL_CHARGING_PENDING`.
- **Ideamart's pending states are unclear.** Its docs list `PENDING` and "PENDING CHARGE" for register, and `PENDING / CHARGE` for getStatus [IS]. It is not clear whether `CHARGE` is a separate value or half of `PENDING CHARGE`.
- **mSpace samples carry stray full stops** (`UNREGISTERED.`, `REGISTERED.`, version `1.0.`) [M subscription/userSubscription, subscriberStatus, notify]. These are doc artifacts, not real values.
- **The mSpace page reuses Ideamart text.** One SMS schema says "Please check the issue with Idea Mart system administrator" [M sms/send, `responseOfSendServices-2`]. This fits the identical mSpace/bdapps OTP wording: the pages come from one template.
- **Docs and driver code agree** on endpoints and on every exact string the middle layer matches.

For our shared code table: E1850, E1851, E1852 and S1000 are safe to map by code everywhere. E1853 is the one code whose meaning depends on the source, and our `OtpFlowService.php:384-391` currently accepts only the spaced `INITIAL CHARGING PENDING` (mSpace passes by platform instead).

---

## 6. Sources

All pages were fetched on 2026-10-29 with `curl` rather than a summarising fetcher, so the strings stay byte-exact. All of them loaded (HTTP 200); none was truncated. The mSpace and bdapps pages are Redoc pages. Their response schemas, where the status codes live, are only in the OpenAPI spec embedded in the page (`__redoc_state`), and the browser renders them when you open "Responses → 200". A plain-text fetch of the HTML misses them, so they were read from that embedded spec.

- **[IO]** Ideamart OTP API (Document Version v1.1, API Version 1.2): https://docs.ideamart.io/developer-docs/otp-api/. Sections: "Submit OTP Request → Response - Error → Already Registered", "Verify PIN → Response - Success", "Response - Error → Wront OTP", "OTP Expired", "OTP Attempt exceeded".
- **[IC]** Ideamart Response Codes: https://docs.ideamart.io/developer-docs/response-codes/, section "Complete Error Codes list".
- **[IS]** Ideamart Subscription API: https://docs.ideamart.io/developer-docs/subscription-api/. Sections: "Register / Unregister response parameters", "Subscription status response parameters", "Subscription Notification Service".
- **[K08]** Ideamart-as-a-Skill `references/08-status-codes.md`, cited by line: https://github.com/hSenidMobileCPaaS/Ideamart-as-a-Skill/blob/main/references/08-status-codes.md (repo head `3574731`, 2026-09-17; file last changed `34af9e8`, 2026-08-13). Section "Complete official error code list".
- **[K04]** Same repo, `references/04-subscription.md`, cited by line. Sections "Register / Unregister → Response parameters", "Subscription Status", "OTP".
- **[K13]** Same repo, `references/13-curl-reference.md`, cited by line. Sections "OTP Request → Status codes for this endpoint" (lines 689-696), "OTP Verify → Response fields / Status codes for this endpoint" (lines 758-776), "Subscription Notification → Payload fields" (line 1315).
- **[M]** mSpace API Documentation (1.0): https://mspace.lk/API_Documentation/mobitel_tap_api.html. Tags such as `[M otp/verify]` name the page's left-nav section. Embedded-spec schemas:
  - `otp/request` → `responseOfOtpRequest.statusDetail`
  - `otp/verify` → `responseOfOtpVerify.subscriberId` and `.subscriptionStatus`
  - `subscription/userSubscription` → `responseOfUserSubscription`
  - `subscription/subscriberStatus` → `responseOfSubscriberStatus`
  - `subscription/getSubscriberChargingInfo` → `responseOfgetSubscriberChargingInfo`, `subscriberInfo`
  - `subscription/getSubscriberList` → `subscribersData`
  - `subscription/notify` → `subscriberNotify`
  - `sms/send` → `responseOfSendServices`, `responseOfSendServices-2`
  - `caas/otpGeneration` (path `/caas/direct/debit`) → `responseOfDirectDebit`
  - `caas/chargingNotification` → `responseOfChargerNotificationCallback`
- **[B]** BDApps API Documentation (1.0): https://dev.bdapps.com/API_Documentation/bdapps_tap_api.html. Same section names as mSpace. Embedded-spec schemas sit under `components.schemas.type.allOf[n]`:
  - `x-responseOfOtpRequest`, `x-responseOfOtpVerify` (n=4)
  - `x-responseOfUserSubscription`, `x-responseOfSubscriberStatus` (n=3)
  - `x-responseOfSendServices`, `x-destinationResponses` (n=0)
  - `/subscription/notify` request body `status`
- **Middle-layer code:** `/Users/chinmoy/projects/personal/telco-platform-laravel/app/…` at commit `469732e`, cited as file:line in sections 3–5.
- **[G8]** Middle-layer notes `/Users/chinmoy/projects/personal/telco-platform-laravel/docs/antigravity-digests/G8.md`, cited by line. This is a digest of the production server's analysis docs (Aug 2026): observations, not provider docs. The lines 25-35 table counts errors per tenant across all channels.
- **[G6]** `/Users/chinmoy/projects/personal/telco-platform-laravel/docs/antigravity-digests/G6.md`, cited by line (legacy-app DB status counts).
- **[PD]** `/Users/chinmoy/projects/personal/telco-platform-laravel/docs/plans/2026-09-28-production-defects.md`, cited by line.
