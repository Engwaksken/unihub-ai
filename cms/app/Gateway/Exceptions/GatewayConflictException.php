<?php

namespace App\Gateway\Exceptions;

/** 409: idempotency conflict/replay unavailable, or a state conflict (e.g. duplicate slug). */
class GatewayConflictException extends GatewayException {}
