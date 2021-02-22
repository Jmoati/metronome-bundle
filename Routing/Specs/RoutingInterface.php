<?php

namespace Jmoati\MetronomeBundle\Routing\Specs;

use Amp\Http\Server\Request;
use Amp\Promise;

interface RoutingInterface
{
    public function isSupported(Request $request): bool;
    public function handle(Request $request): Promise;
    public static function getDefaultPriority(): int;
}
