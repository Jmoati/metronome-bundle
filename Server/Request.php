<?php


namespace Jmoati\MetronomeBundle\Server;

use Symfony\Component\HttpFoundation\Request as SfRequest;

class Request
{
    public function __construct(
        public string $uri,
        public string $method = SfRequest::METHOD_GET,
        public array $parameters = [],
        public array $cookies = [],
        public array $files = [],
        public array $server = [],
        public ?string $contentPath = null,
        public array $headers = [],
    ) {

    }
}
