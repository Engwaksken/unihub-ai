<?php

namespace App\Gateway\Exceptions;

/** 401 UNAUTHENTICATED: the gateway rejected the CMS service token or actor assertion (misconfiguration or clock skew). */
class GatewayAuthenticationException extends GatewayException {}
