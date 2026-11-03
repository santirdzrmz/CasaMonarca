<?php

namespace App\Services\Auth;

class TotpService
{
    private const OTP_DIGITS = 6;

    private const TIME_STEP_SECONDS = 30;

    public function generateSecret(int $length = 32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $alphabetLength = strlen($alphabet);
        $secret = '';

        for ($index = 0; $index < $length; $index++) {
            $secret .= $alphabet[random_int(0, $alphabetLength - 1)];
        }

        return $secret;
    }

    public function currentCode(string $secret, ?int $timestamp = null): string
    {
        $counter = intdiv($timestamp ?? time(), self::TIME_STEP_SECONDS);

        return $this->codeForCounter($secret, $counter);
    }

    public function verify(string $secret, string $candidateCode, int $window = 1): bool
    {
        return $this->matchingCounter($secret, $candidateCode, $window) !== null;
    }

    /**
     * Returns the time-step counter the code belongs to, so callers can
     * reject a code that was already used (RFC 6238 §5.2).
     */
    public function matchingCounter(string $secret, string $candidateCode, int $window = 1): ?int
    {
        $normalizedCode = preg_replace('/\s+/', '', $candidateCode) ?? '';

        if (! preg_match('/^\d{6}$/', $normalizedCode)) {
            return null;
        }

        $counter = intdiv(time(), self::TIME_STEP_SECONDS);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->codeForCounter($secret, $counter + $offset), $normalizedCode)) {
                return $counter + $offset;
            }
        }

        return null;
    }

    private function codeForCounter(string $secret, int $counter): string
    {
        if ($counter < 0) {
            return '';
        }

        $binarySecret = $this->decodeBase32Secret($secret);

        if ($binarySecret === '') {
            return '';
        }

        $counterBytes = pack(
            'N2',
            ($counter >> 32) & 0xFFFFFFFF,
            $counter & 0xFFFFFFFF,
        );

        $hash = hash_hmac('sha1', $counterBytes, $binarySecret, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $segment = substr($hash, $offset, 4);
        $value = unpack('N', $segment)[1] & 0x7FFFFFFF;
        $code = $value % (10 ** self::OTP_DIGITS);

        return str_pad((string) $code, self::OTP_DIGITS, '0', STR_PAD_LEFT);
    }

    private function decodeBase32Secret(string $secret): string
    {
        $normalizedSecret = strtoupper($secret);
        $normalizedSecret = str_replace([' ', '-', '='], '', $normalizedSecret);

        if ($normalizedSecret === '') {
            return '';
        }

        if (! preg_match('/^[A-Z2-7]+$/', $normalizedSecret)) {
            return '';
        }

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';

        foreach (str_split($normalizedSecret) as $character) {
            $value = strpos($alphabet, $character);

            if ($value === false) {
                return '';
            }

            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                continue;
            }

            $bytes .= chr(bindec($chunk));
        }

        return $bytes;
    }
}
