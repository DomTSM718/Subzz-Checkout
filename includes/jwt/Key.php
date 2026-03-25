<?php
/**
 * Vendored from firebase/php-jwt v6.10.0
 * License: BSD-3-Clause
 */

namespace Firebase\JWT;

use InvalidArgumentException;

class Key
{
    private $keyMaterial;
    private $algorithm;

    public function __construct(string $keyMaterial, string $algorithm)
    {
        if (empty($keyMaterial)) {
            throw new InvalidArgumentException('Key material must not be empty');
        }
        if (empty($algorithm)) {
            throw new InvalidArgumentException('Algorithm must not be empty');
        }
        $this->keyMaterial = $keyMaterial;
        $this->algorithm = $algorithm;
    }

    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    public function getKeyMaterial(): string
    {
        return $this->keyMaterial;
    }
}
