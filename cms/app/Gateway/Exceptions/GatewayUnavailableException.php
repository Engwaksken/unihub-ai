<?php

namespace App\Gateway\Exceptions;

/** Connection failure, timeout, 5xx, or a response that is not a valid envelope. */
class GatewayUnavailableException extends GatewayException {}
