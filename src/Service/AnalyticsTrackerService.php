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
 */
class AnalyticsTrackerService
{
    public function __construct(
        private readonly array $config,
        private readonly ?FacebookCapiService $capiService,
        private readonly UserInfoService $userInfoService,
        private readonly SessionService $session,
        private readonly UserLoggerInterface $userLogger,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Retrieves normalized visitor and request device info.
     */
    public function getUserInfo(Request $request): array
    {
        return $this->userInfoService->get($request);
    }

    /**
     * Gets or generates the long-lived visitor ID.
     */
    public function getVisitorId(): string
    {
        return (string) ($this->session->get(SessionKey::VISITOR_ID) ?? 'v_' . uniqid());
    }

    /**
     * Tracks a PageView event, logging the visit and dispatching CAPI if configured.
     *
     * @param Request $request
     * @param string $pageRoute e.g. '/' or '/otp' or '/thanks'
     * @param string $prefix e.g. 'pgview-' or 'pgview-otp-'
     * @param PhoneNumber|array|null $phoneData
     * @param bool $logVisitToDb
     * @param bool $skipOnError If true and SessionKey::ERROR exists in session, skips CAPI event.
     * @param string|SessionKey|null $sessionKey Session key to store the event ID under. Defaults to SessionKey::PAGE_VIEW_ID.
     * @return string|null The PageView event ID or null if skipped.
     */
    public function trackPageView(
        Request $request,
        string $pageRoute,
        string $prefix = 'pgview-',
        PhoneNumber|array|null $phoneData = null,
        bool $logVisitToDb = false,
        bool $skipOnError = false,
        string|SessionKey|null $sessionKey = null
    ): ?string {
        $userInfo = $this->getUserInfo($request);
        $this->logger->info("New page visit. {$pageRoute}", $userInfo);

        if ($logVisitToDb) {
            $this->userLogger->logVisit(
                $this->getVisitorId(),
                $userInfo['ip'],
                $userInfo['useragent'],
                $phoneData['capi_format'] ?? null
            );
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

        // Generate event ID
        $pageViewEventId = $prefix . bin2hex(random_bytes(16));

        $phoneForMatching = $phoneData['capi_format'] ?? $this->session->get(SessionKey::PHONE_DATA)['capi_format'] ?? null;
        $visitorId = $this->getVisitorId();

        $capiParams = $this->capiService->processRequest($request);
        $fbc = $capiParams['fbc'] ?? null;
        $fbp = $capiParams['fbp'] ?? null;
        $clientIpAddress = $capiParams['client_ip_address'] ?? null;

        if ($fbc) {
            $this->session->set(SessionKey::FBC, $fbc);
        }
        if ($fbp) {
            $this->session->set(SessionKey::FBP, $fbp);
        }

        $userDataArray = [
            'ip' => $clientIpAddress ?? $userInfo['ip'],
            'agent' => $userInfo['useragent'],
            'phone' => $phoneForMatching,
            'fbp' => $fbp ?? $this->session->get(SessionKey::FBP),
            'fbc' => $fbc ?? $this->session->get(SessionKey::FBC),
            'external_id' => $visitorId,
        ];

        if (!empty($phoneForMatching)) {
            $userDataArray['country'] = 'lk';
        }

        $this->capiService->sendEvent(
            'PageView',
            $pageViewEventId,
            $request->getUri(),
            $userDataArray
        );

        $targetSessionKey = $sessionKey ?? SessionKey::PAGE_VIEW_ID;
        $this->session->set($targetSessionKey, $pageViewEventId);

        $this->logger->info("New PageView triggered. {$pageRoute}", [
            'page_view_id' => $pageViewEventId,
            'phone_for_matching' => $phoneForMatching
        ]);

        return $pageViewEventId;
    }

    /**
     * Logs a visit associated with a validated phone number and stores FB cookies in the session.
     */
    public function logVisitWithPhone(Request $request, PhoneNumber|array $phoneData): void
    {
        $userInfo = $this->getUserInfo($request);
        $visitorId = $this->getVisitorId();
        $phoneCapi = $phoneData['capi_format'] ?? null;

        $this->userLogger->logVisit(
            $visitorId,
            $userInfo['ip'],
            $userInfo['useragent'],
            $phoneCapi
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
     * Fires a Lead event via Facebook CAPI.
     */
    public function trackLead(Request $request, PhoneNumber|array $phoneData, ?string $leadId = null): ?string
    {
        if ($this->capiService === null) {
            return null;
        }

        $leadEventId = $leadId ?? ('lead-' . bin2hex(random_bytes(16)));
        $userInfo = $this->getUserInfo($request);
        $visitorId = $this->getVisitorId();
        $phoneForMatching = $phoneData['capi_format'] ?? '';

        $fbp = $this->session->get(SessionKey::FBP);
        $fbc = $this->session->get(SessionKey::FBC);

        $capiParams = $this->capiService->processRequest($request);
        $clientIpAddress = $capiParams['client_ip_address'] ?? null;

        $userDataArray = [
            'ip' => $clientIpAddress ?? $userInfo['ip'],
            'agent' => $userInfo['useragent'],
            'phone' => $phoneForMatching,
            'fbp' => $fbp,
            'fbc' => $fbc,
            'external_id' => $visitorId,
            'country' => 'lk',
        ];

        $customData = [
            'currency' => 'USD',
            'value' => (string) ($phoneData['value'] ?? '0.01'),
        ];

        $this->capiService->sendEvent(
            'Lead',
            $leadEventId,
            $request->getUri(),
            $userDataArray,
            $customData
        );

        $this->logger->info('Lead event triggered.', [
            'lead_id' => $leadEventId,
            'phone' => $phoneForMatching
        ]);

        return $leadEventId;
    }

    /**
     * Fires a CompleteRegistration event via Facebook CAPI.
     */
    public function trackCompleteRegistration(Request $request, PhoneNumber|array $phoneData, string $regId): ?string
    {
        if ($this->capiService === null) {
            return null;
        }

        $userInfo = $this->getUserInfo($request);
        $visitorId = $this->getVisitorId();
        $phoneForMatching = $phoneData['capi_format'] ?? '';

        $fbp = $this->session->get(SessionKey::FBP);
        $fbc = $this->session->get(SessionKey::FBC);

        $capiParams = $this->capiService->processRequest($request);
        $clientIpAddress = $capiParams['client_ip_address'] ?? null;

        $userDataArray = [
            'ip' => $clientIpAddress ?? $userInfo['ip'],
            'agent' => $userInfo['useragent'],
            'phone' => $phoneForMatching,
            'fbp' => $fbp,
            'fbc' => $fbc,
            'external_id' => $visitorId,
            'country' => 'lk',
        ];

        $customData = [
            'currency' => 'USD',
            'value' => (string) ($phoneData['value'] ?? '0.01'),
        ];

        $this->capiService->sendEvent(
            'CompleteRegistration',
            $regId,
            $request->getUri(),
            $userDataArray,
            $customData
        );

        $this->logger->info('CompleteRegistration event triggered.', ['reg_id' => $regId]);

        return $regId;
    }

    public function getCapiService(): ?FacebookCapiService
    {
        return $this->capiService;
    }

    public function getUserLogger(): UserLoggerInterface
    {
        return $this->userLogger;
    }

    public function getUserInfoService(): UserInfoService
    {
        return $this->userInfoService;
    }
}
