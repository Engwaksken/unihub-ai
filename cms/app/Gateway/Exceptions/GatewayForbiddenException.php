<?php

namespace App\Gateway\Exceptions;

/** 403 FORBIDDEN: the actor's role lacks the capability. */
class GatewayForbiddenException extends GatewayException {}
