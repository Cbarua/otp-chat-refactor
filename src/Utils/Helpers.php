<?php
// src/Utils/Helpers.php

namespace App\Utils;

/**
 * General-purpose helper functions.
 */
class Helpers
{
    /**
     * Gets the current page URL.
     * @return string
     */
    public static function getCurrentUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return $protocol . $host . $uri;
    }

    /**
     * Gets the client's IP address from various headers.
     * @return string
     */
    public static function getClientIp(): string
    {
        // Headers to check, in order of preference.
        // Added CF_CONNECTING_IP for Cloudflare support.
        $ipHeaders = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($ipHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                // HTTP_X_FORWARDED_FOR can be a comma-separated list
                $ip = explode(',', $_SERVER[$header])[0];
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0'; // Fallback
    }

    /**
     * Gathers basic user info for logging.
     * @return array
     */
    public static function getUserInfo(): array
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN_UA';
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
            'ip' => self::getClientIp(),
            'useragent' => $userAgent
        ];
    }
}