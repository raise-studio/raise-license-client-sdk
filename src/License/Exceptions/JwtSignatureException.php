<?php

namespace RaiseStudio\License\Exceptions;

/**
 * Thrown when JWT signature verification fails (token may be tampered).
 */
class JwtSignatureException extends LicenseException
{
}
