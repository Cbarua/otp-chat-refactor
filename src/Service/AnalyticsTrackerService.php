<?php
// src/Service/AnalyticsTrackerService.php
declare(strict_types=1);

namespace App\Service;

use App\DTO\PhoneNumber;
use App\Enum\SessionKey;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Encapsulates all visitor identification, analytics tracking, and Facebook CAPI event dispatching.
 * Each page makes one call, which sends the page's events and returns the Pixel and GA variables the layout prints.
 * HEAD requests come from uptime monitors, not visitors, so they send no PageView.
 */
class AnalyticsTrackerService
{
    public function __construct(
        private readonly array $config,
        private readonly ?FacebookCapiService $capiService,
        private readonly UserInfoService $userInfoService,
        private readonly SessionService $session,
        private readonly SimpleUserLoggerService $userLogger,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Tracks the phone form: logs the visit unless a flash is showing, and sends a PageView unless an error is.
     * Returns the layout's tracking variables; after an error the Pixel gets the earlier PageView id.
     */
    public function trackPhoneForm(Request $request, ?PhoneNumber $phone): array
    {
        $flashShowing = $this->session->has(SessionKey::ALREADY_REGISTERED)
            || $this->session->has(SessionKey::ERROR_MESSAGE);
        $this->trackPageView($request, '/', 'pgview-', SessionKey::PAGE_VIEW_ID, $phone, logVisitToDb: !$flashShowing);

        return $this->layoutVariables($phone, $this->session->get(SessionKey::PAGE_VIEW_ID));
    }

    /**
     * Tracks the OTP form: sends a PageView unless an error is showing, and the Lead when one is pending.
     * Returns the layout's tracking variables and the Lead id for the Pixel.
     */
    public function trackOtpForm(Request $request, ?PhoneNumber $phone, ?string $leadId): array
    {
        $this->trackPageView($request, '/otp', 'pgview-otp-', SessionKey::PAGE_VIEW_ID_OTP, $phone);

        if ($this->capiService !== null && $leadId !== null && $phone !== null) {
            $this->sendConversion('Lead', $leadId, $request, $phone);
            $this->logger->info('Lead event triggered.', [
                'lead_id' => $leadId,
                'phone' => $phone->capiFormat
            ]);
        }

        return $this->layoutVariables($phone, $this->session->get(SessionKey::PAGE_VIEW_ID_OTP)) + [
            'leadEventId' => $leadId,
        ];
    }

    /**
     * Tracks the thank-you page: sends a PageView and the CompleteRegistration.
     * Returns the layout's tracking variables, the registration id and the conversion's value for the Pixel.
     */
    public function trackThankYouPage(Request $request, PhoneNumber $phone, string $regId): array
    {
        $pageViewEventId = $this->trackPageView(
            $request,
            '/thanks',
            'pgview-thanks-',
            SessionKey::PAGE_VIEW_ID_THANK_YOU,
            $phone,
            skipOnError: false
        );

        if ($this->capiService !== null) {
            $this->sendConversion('CompleteRegistration', $regId, $request, $phone);
            $this->logger->info('CompleteRegistration event triggered.', ['reg_id' => $regId]);
        }

        return $this->layoutVariables($phone, $pageViewEventId) + [
            'regId' => $regId,
            'eventData' => json_encode($this->conversionValue($phone)),
        ];
    }

    /**
     * Logs a visit associated with a validated phone number and stores FB cookies in the session.
     */
    public function logVisitWithPhone(Request $request, PhoneNumber $phone): void
    {
        $userInfo = $this->userInfoService->get($request);

        $this->userLogger->logVisit(
            $this->getVisitorId(),
            $userInfo['ip'],
            $userInfo['useragent'],
            $phone->capiFormat
        );

        $fbp = $request->request->get('fbp');
        $fbc = $request->request->get('fbc');

        if ($fbp !== null) {
            $this->session->set(SessionKey::FBP, $fbp);
        }
        if ($fbc !== null) {
            $this->session->set(SessionKey::FBC, $fbc);
        }
    }

    /**
     * Gets or generates the long-lived visitor ID.
     */
    private function getVisitorId(): string
    {
        return (string) ($this->session->get(SessionKey::VISITOR_ID) ?? 'v_' . uniqid());
    }

    /**
     * Logs the page visit and sends a PageView, storing its id under $sessionKey.
     *
     * @return string|null The PageView event ID, or null if none was sent.
     */
    private function trackPageView(
        Request $request,
        string $pageRoute,
        string $prefix,
        SessionKey $sessionKey,
        ?PhoneNumber $phone,
        bool $logVisitToDb = false,
        bool $skipOnError = true
    ): ?string {
        if ($request->isMethod('HEAD')) {
            return null;
        }

        $userInfo = $this->userInfoService->get($request);
        $this->logger->info("New page visit. {$pageRoute}", $userInfo);

        if ($logVisitToDb) {
            // The visit log of a page view leaves out the phone.
            $this->userLogger->logVisit($this->getVisitorId(), $userInfo['ip'], $userInfo['useragent']);
        }

        if ($this->capiService === null) {
            return null;
        }

        if ($skipOnError && $this->session->has(SessionKey::ERROR_MESSAGE)) {
            $this->logger->notice("Rendering {$pageRoute} to display error, skipping new PageView.", [
                'error' => $this->session->get(SessionKey::ERROR_MESSAGE)
            ]);
            return null;
        }

        $pageViewEventId = $prefix . bin2hex(random_bytes(16));

        // The request's cookies replace the stored ones, so the page's other events match on them too.
        $capiParams = $this->capiService->processRequest($request);
        if ($capiParams['fbc'] ?? null) {
            $this->session->set(SessionKey::FBC, $capiParams['fbc']);
        }
        if ($capiParams['fbp'] ?? null) {
            $this->session->set(SessionKey::FBP, $capiParams['fbp']);
        }

        $this->capiService->sendEvent(
            'PageView',
            $pageViewEventId,
            $request->getUri(),
            $this->userData($request, $capiParams, $phone)
        );

        $this->session->set($sessionKey, $pageViewEventId);

        $this->logger->info("New PageView triggered. {$pageRoute}", [
            'page_view_id' => $pageViewEventId,
            'phone_for_matching' => $phone?->capiFormat
        ]);

        return $pageViewEventId;
    }

    /**
     * Sends a Lead or CompleteRegistration with the conversion's value.
     */
    private function sendConversion(string $eventName, string $eventId, Request $request, PhoneNumber $phone): void
    {
        $this->capiService->sendEvent(
            $eventName,
            $eventId,
            $request->getUri(),
            $this->userData($request, $this->capiService->processRequest($request), $phone),
            $this->conversionValue($phone)
        );
    }

    /**
     * Builds the CAPI user data: the visitor, the phone with its country, and the fbp/fbc the session holds.
     */
    private function userData(Request $request, array $capiParams, ?PhoneNumber $phone): array
    {
        $userInfo = $this->userInfoService->get($request);

        $userData = [
            'ip' => $capiParams['client_ip_address'] ?? $userInfo['ip'],
            'agent' => $userInfo['useragent'],
            'phone' => $phone?->capiFormat,
            'fbp' => $this->session->get(SessionKey::FBP),
            'fbc' => $this->session->get(SessionKey::FBC),
            'external_id' => $this->getVisitorId(),
        ];

        if ($phone !== null) {
            $userData['country'] = strtolower($phone->country);
        }

        return $userData;
    }

    /**
     * The conversion's value and currency, for CAPI and the Pixel.
     */
    private function conversionValue(PhoneNumber $phone): array
    {
        return [
            'currency' => 'USD',
            'value' => (string) $phone->value,
        ];
    }

    /**
     * The Pixel and GA variables the layout prints.
     */
    private function layoutVariables(?PhoneNumber $phone, ?string $pageViewEventId): array
    {
        return [
            'pixelId' => $this->config['facebook']['pixel_id'] ?? null,
            'externalId' => $this->getVisitorId(),
            'phoneCapi' => $phone?->capiFormat,
            'country' => $phone === null ? null : strtolower($phone->country),
            'pageViewEventId' => $pageViewEventId,
            'gaMeasurementId' => $this->config['google']['ga_measurement_id'] ?? null,
        ];
    }
}
