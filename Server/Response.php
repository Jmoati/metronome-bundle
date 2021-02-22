<?php

namespace Jmoati\MetronomeBundle\Server;

use Symfony\Component\HttpFoundation\Response as SfResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class Response
{
    public function __construct(
        public bool|string $content,
        public ?ResponseHeaderBag $headers = null,
        public int $code = SfResponse::HTTP_OK
    ){
        if (null === $this->headers) {
            $this->headers = new ResponseHeaderBag();
        }
    }
}