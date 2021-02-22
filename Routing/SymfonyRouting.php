<?php

namespace Jmoati\MetronomeBundle\Routing;

use Amp\Http\Server\Request;
use Amp\Promise;
use Jmoati\MetronomeBundle\Routing\Specs\RoutingInterface;
use Jmoati\MetronomeBundle\Server\Pool;

final class SymfonyRouting implements RoutingInterface
{
    private Pool $pool;
    
    public function __construct(
        string $workerPath,
        string $tmpPath,
    ) {
        $this->pool = new Pool($workerPath, $tmpPath);
    }
    
    public function isSupported(Request $request): bool
    {
        return true;
    }
    
    public function handle(Request $request): Promise
    {
        return $this->pool->handle($request);
    }
    
    public static function getDefaultPriority(): int
    {
        return -100;
    }
}
