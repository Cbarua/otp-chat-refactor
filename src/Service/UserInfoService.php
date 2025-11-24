<?php
// src/Service/UserInfoService.php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

class UserInfoService
{
    /**
     * Retrieves client IP address using a specific header preference order.
     * This replicates the logic from Helpers::getClientIp() but uses the Request object.
     *
     * @param Request $request The current Symfony Request object.
     * @return string The client's IP address.
     */
    private function getClientIpFromRequest(Request $request): string
    {
        // Headers to check, in order of preference.
        // Added CF_CONNECTING_IP for Cloudflare support, as in original Helpers.
        $ipHeaders = [
            'CF-Connecting-IP', // Standard Cloudflare header
            'CLIENT_IP',
            'X_FORWARDED_FOR',
            'X_FORWARDED',
            'FORWARDED_FOR',
            ',"FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($ipHeaders as $header) {
            // Request->server->get() checks for HTTP_ header prefixes automatically
            $value = $request->headers->get($header) ?? $request->server->get('HTTP_' . $header, $request->server->get($header));
            if (!empty($value)) {
                // HTTP_X_FORWARDED_FOR can be a comma-separated list
                $ip = explode(',', $value)[0];
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0'; // Fallback
    }

    /**
     * Gathers comprehensive user info from a Request object.
     * This replicates the logic and output format from Helpers::getUserInfo().
     *
     * @param Request $request The current Symfony Request object.
     * @return array An associative array containing 'os', 'device', 'ip', and 'useragent'.
     */
    public function get(Request $request): array
    {
        $userAgent = $request->headers->get('User-Agent') ?? 'UNKNOWN_UA';
        $os = 'Unknown OS';
        $device = 'Unknown Device';

        // Detect OS
        if (preg_match('/Android\s([\d\.]+)/i', $userAgent, $match)) {
            $os = 'Android ' . $match[1];
        } elseif (preg_match('/iPhone OS\s([\d_]+)/i', $userAgent, $match)) {
            $os = 'iOS ' . str_replace('_', '.', $match[1]);
        } elseif (preg_match('/Windows NT\s([\d\.]+)/i', $userAgent, $match)) {
            $os = 'Windows ' . $match[1];
        } elseif (stripos($userAgent, 'Mac OS X') !== false) {
            $os = 'macOS';
        } elseif (stripos($userAgent, 'Linux') !== false) {
            $os = 'Linux';
        }

        // Detect Device
        if (preg_match('/Android\s[\d\.]+;\s([^;)\[]+)/i', $userAgent, $match)) {
            $device = trim($match[1]);
        } elseif (preg_match('/\((iPhone|iPad|iPod)/i', $userAgent, $match)) {
            $device = $match[1];
        } elseif (preg_match('/\(([^;]+);/i', $userAgent, $match)) {
            $device = trim($match[1]);
        }

        return [
            'os' => $os,
            'device' => $device,
            'ip' => $this->getClientIpFromRequest($request),
            'useragent' => $userAgent
        ];
    }
}