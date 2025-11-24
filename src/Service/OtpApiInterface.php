<?php
namespace App\Service;

interface OtpApiInterface
{
    /**
     * Requests an OTP from the provider for a specific platform.
     *
     * @param string $platform The platform identifier (e.g., 'ideamart', 'mspace') to use.
     * @param string $subscriberId The 'tel:...' formatted number.
     * @param array $metaData Additional data for the API call.
     * @param array $excludeUrls An array of base URLs to exclude from this attempt.
     * @return array The JSON response from the first successful call, or the last failed response.
     */
    public function getOtp(string $platform, string $subscriberId, array $metaData, array $excludeUrls = []): array;

    /**
     * Verifies an OTP with the provider using a token from the getOtp call.
     *
     * @param array $verificationToken The data bundle returned from a successful getOtp call.
     * @param string $otp The 6-digit user-provided OTP.
     * @return array The JSON response as an array.
     */
    public function verifyOtp(array $verificationToken, string $otp): array;
}