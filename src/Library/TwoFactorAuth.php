<?php
declare(strict_types=1);

namespace Npf\Library;

use Exception;
use Npf\Exception\InternalError;

/**
 * Class TwoFactorAuth
 * @package Npf\Library
 */
final class TwoFactorAuth
{
    /**
     * @var int Code Length
     */
    private int $_codeLength = 6;

    /**
     * @var array Get array with all 32 characters for decoding from/encoding to base32.
     */
    private array $base32LookupTable = [
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', //  7
        'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', // 15
        'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', // 23
        'Y', 'Z', '2', '3', '4', '5', '6', '7', // 31
        '=',  // padding char
    ];

    /**
     * Create new secret.
     * 16 characters, randomly chosen from the allowed base32 characters.
     * @param int $secretLength
     * @return string
     * @throws InternalError
     * @throws Exception
     */
    final public function createSecret(int $secretLength = 16): string
    {
        $validChars = $this->base32LookupTable;
        // Valid secret lengths are 80 to 640 bits
        if ($secretLength < 16 || $secretLength > 128) {
            throw new InternalError('TwoFactorAuth: Bad secret length');
        }
        $secret = '';
        $rnd = FALSE;
        if (function_exists('random_bytes')) {
            $rnd = random_bytes($secretLength);
        } elseif (function_exists('mcrypt_create_iv')) {
            $rnd = random_bytes($secretLength);
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $rnd = openssl_random_pseudo_bytes($secretLength, $cryptoStrong);
            if (!$cryptoStrong) {
                $rnd = FALSE;
            }
        }
        if ($rnd !== FALSE) {
            for ($i = 0; $i < $secretLength; ++$i) {
                $secret .= $validChars[ord($rnd[$i]) & 31];
            }
        } else {
            throw new InternalError('No source of secure random');
        }
        return $secret;
    }

    /**
     * Check if the code is correct. This will accept codes starting from $discrepancy * 30sec ago to $discrepancy * 30sec from now.
     * @param string $secret
     * @param string $code
     * @param int $discrepancy This is the allowed time drift in 30 second units (8 means 4 minutes before or after)
     * @param int|NULL $currentTimeSlice time slice if we want use other that time()
     * @return bool
     */
    final public function verifyCode(string $secret, string $code, int $discrepancy = 1, ?int $currentTimeSlice = NULL): bool
    {
        if ($currentTimeSlice === NULL) {
            $currentTimeSlice = floor(time() / 30);
        }
        if (is_int($code))
            $code = (string)$code;
        if (strlen($code) != 6) {
            return FALSE;
        }
        for ($i = -$discrepancy; $i <= $discrepancy; ++$i) {
            $calculatedCode = $this->getCode($secret, $currentTimeSlice + $i);
            if ($this->timingSafeEquals($calculatedCode, $code)) {
                return TRUE;
            }
        }
        return FALSE;
    }

    /**
     * Calculate the code, with given secret and point in time.
     * @param string $secret
     * @param int|NULL $timeSlice
     * @return string
     */
    final public function getCode(string $secret, ?int $timeSlice = NULL): string
    {
        if ($timeSlice === NULL) {
            $timeSlice = floor(time() / 30);
        }
        $secretKey = $this->_base32Decode($secret);
        $time = chr(0) . chr(0) . chr(0) . chr(0) . pack('N*', $timeSlice);
        $hMac = hash_hmac('SHA1', $time, $secretKey, TRUE);
        $offset = ord(substr($hMac, -1)) & 0x0F;
        $hashPart = substr($hMac, $offset, 4);
        $value = unpack('N', $hashPart);
        $value = $value[1];
        $value = $value & 0x7FFFFFFF;
        $modulo = pow(10, $this->_codeLength);
        return str_pad((string)($value % $modulo), $this->_codeLength, '0', STR_PAD_LEFT);
    }

    /**
     * Helper class to decode base32.
     * @param string $secret
     * @return bool|string
     */
    private function _base32Decode(string $secret): bool|string
    {
        if (empty($secret)) {
            return '';
        }
        $base32chars = $this->base32LookupTable;
        $base32charsFlipped = array_flip($base32chars);
        $paddingCharCount = substr_count($secret, $base32chars[32]);
        $allowedValues = [6, 4, 3, 1, 0];
        if (!in_array($paddingCharCount, $allowedValues)) {
            return FALSE;
        }
        for ($i = 0; $i < 4; ++$i) {
            if ($paddingCharCount == $allowedValues[$i] &&
                substr($secret, -($allowedValues[$i])) != str_repeat($base32chars[32], $allowedValues[$i])
            ) {
                return FALSE;
            }
        }
        $secret = str_replace('=', '', $secret);
        $secret = str_split($secret);
        $binaryString = '';
        for ($i = 0; $i < count($secret); $i = $i + 8) {
            $x = '';
            if (!in_array($secret[$i], $base32chars)) {
                return FALSE;
            }
            for ($j = 0; $j < 8; ++$j) {
                $x .= str_pad(base_convert(@$base32charsFlipped[@$secret[$i + $j]], 10, 2), 5, '0', STR_PAD_LEFT);
            }
            $eightBits = str_split($x, 8);
            for ($z = 0; $z < count($eightBits); ++$z) {
                $binaryString .= (($y = chr((int)base_convert($eightBits[$z], 2, 10))) || ord($y) == 48) ? $y : '';
            }
        }
        return $binaryString;
    }

    /**
     * A timing safe equals comparison
     * @param string $safeString The internal (safe) value to be checked
     * @param string $userString The user submitted (unsafe) value
     * @return bool True if the two strings are identical
     */
    private function timingSafeEquals(string $safeString, string $userString): bool
    {
        if (function_exists('hash_equals')) {
            return hash_equals($safeString, $userString);
        }
        $safeLen = strlen($safeString);
        $userLen = strlen($userString);
        if ($userLen != $safeLen) {
            return FALSE;
        }
        $result = 0;
        for ($i = 0; $i < $userLen; ++$i) {
            $result |= (ord($safeString[$i]) ^ ord($userString[$i]));
        }
        // They are only identical strings if $result is exactly 0...
        return $result === 0;
    }

    /**
     * Set the code length, should be >=6.
     * @param int $length
     * @return self
     */
    final public function setCodeLength(int $length): self
    {
        $this->_codeLength = $length;
        return $this;
    }
}