<?php

namespace App\Services\Commands;

use RuntimeException;

/**
 * Thrown by a command's authorize() when the acting user may not perform it
 * (out of tenant scope, invalid target, invalid arguments). No mutation or audit
 * row is written when this is thrown.
 */
class CommandAuthorizationException extends RuntimeException {}
