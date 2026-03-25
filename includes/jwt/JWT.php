<?php
/**
 * Vendored from firebase/php-jwt v6.10.0
 * https://github.com/firebase/php-jwt
 * License: BSD-3-Clause
 *
 * Minimal subset: encode() and decode() with HS256 support only.
 * This is sufficient for the Subzz checkout token signing use case.
 */

namespace Firebase\JWT;

use DomainException;
use InvalidArgumentException;
use UnexpectedValueException;

class JWT
{
    private static $supported_algs = array(
        'HS256' => array('hash_hmac', 'SHA256'),
    );

    /**
     * Encode a PHP array into a JWT string.
     *
     * @param array  $payload The payload data
     * @param string $key     The secret key
     * @param string $alg     The signing algorithm (only HS256 supported)
     *
     * @return string A signed JWT
     */
    public static function encode(array $payload, string $key, string $alg = 'HS256'): string
    {
        if (empty($key)) {
            throw new InvalidArgumentException('Key may not be empty');
        }

        if (!isset(static::$supported_algs[$alg])) {
            throw new DomainException('Algorithm not supported');
        }

        $header = array('typ' => 'JWT', 'alg' => $alg);

        $segments = array();
        $segments[] = static::urlsafeB64Encode(json_encode($header));
        $segments[] = static::urlsafeB64Encode(json_encode($payload));

        $signing_input = implode('.', $segments);
        $signature = static::sign($signing_input, $key, $alg);
        $segments[] = static::urlsafeB64Encode($signature);

        return implode('.', $segments);
    }

    /**
     * Decode a JWT string into a PHP object.
     *
     * @param string $jwt The JWT string
     * @param Key    $key The key object with secret and algorithm
     *
     * @return object The decoded payload
     *
     * @throws InvalidArgumentException
     * @throws UnexpectedValueException
     * @throws ExpiredException
     */
    public static function decode(string $jwt, Key $key): object
    {
        $tks = explode('.', $jwt);
        if (count($tks) !== 3) {
            throw new UnexpectedValueException('Wrong number of segments');
        }

        list($headb64, $bodyb64, $cryptob64) = $tks;

        $header = json_decode(static::urlsafeB64Decode($headb64));
        if ($header === null) {
            throw new UnexpectedValueException('Invalid header encoding');
        }

        $payload = json_decode(static::urlsafeB64Decode($bodyb64));
        if ($payload === null) {
            throw new UnexpectedValueException('Invalid claims encoding');
        }

        $sig = static::urlsafeB64Decode($cryptob64);

        if (!isset($header->alg)) {
            throw new UnexpectedValueException('Empty algorithm');
        }

        if ($header->alg !== $key->getAlgorithm()) {
            throw new UnexpectedValueException('Algorithm mismatch');
        }

        // Verify signature
        $signing_input = $headb64 . '.' . $bodyb64;
        if (!static::verify($signing_input, $sig, $key->getKeyMaterial(), $header->alg)) {
            throw new UnexpectedValueException('Signature verification failed');
        }

        // Check expiration
        if (isset($payload->exp) && time() >= $payload->exp) {
            throw new ExpiredException('Expired token');
        }

        // Check not before
        if (isset($payload->nbf) && time() < $payload->nbf) {
            throw new UnexpectedValueException('Token not yet valid');
        }

        return $payload;
    }

    private static function sign(string $msg, string $key, string $alg): string
    {
        list($function, $algorithm) = static::$supported_algs[$alg];
        return hash_hmac($algorithm, $msg, $key, true);
    }

    private static function verify(string $msg, string $signature, string $key, string $alg): bool
    {
        list($function, $algorithm) = static::$supported_algs[$alg];
        $hash = hash_hmac($algorithm, $msg, $key, true);
        return hash_equals($hash, $signature);
    }

    private static function urlsafeB64Encode(string $input): string
    {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }

    private static function urlsafeB64Decode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $input .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($input, '-_', '+/'));
    }
}
