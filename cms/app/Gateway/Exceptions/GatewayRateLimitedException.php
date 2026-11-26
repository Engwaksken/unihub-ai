<?php

namespace App\Gateway\Exceptions;

/** 429 from the gateway. */
class GatewayRateLimitedException extends GatewayException {}
