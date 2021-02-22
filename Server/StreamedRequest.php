<?php

namespace Jmoati\MetronomeBundle\Server;

use Symfony\Component\HttpFoundation\Request as SfRequest;
use Symfony\Component\Lock\LockInterface;

class StreamedRequest extends SfRequest
{
    private ?LockInterface $lock = null;

    public static function create(
        string $uri,
        string $method = 'GET',
        array $parameters = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        $content = null,
        ?LockInterface $lock = null
    ) {
        $request = parent::create($uri, $method, $parameters, $cookies, $files, $server, $content);
        $request->lock = $lock;

        return $request;
    }


    public function getContent(bool $asResource = false, bool $wait = true)
    {
        if (null !== $this->lock && $wait) {
            $this->lock->acquire(true);
            $this->lock->release();
        }

        return parent::getContent($asResource);
    }
}
