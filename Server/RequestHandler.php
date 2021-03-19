<?php

namespace Jmoati\MetronomeBundle\Server;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler as RequestHandlerInterface;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Promise;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use function Amp\call;

final class RequestHandler implements RequestHandlerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;
    
    /** @var callable */
    private $callable;
    
    public function __construct(
        iterable $routing
    ) {
        $routing = iterator_to_array($routing); // mandatory for warmup
        
        $this->callable = function (Request $request) use ($routing) {
            $this->logger->info(sprintf('[%s] %s', $request->getMethod(), $request->getUri()));
            
            foreach ($routing as $router) {
                if ($router->isSupported($request)) {
                    $response =  yield $router->handle($request);
                    $this->logger->info(sprintf('[%s] %d', get_class($router), $response->getStatus()));
        
                    return $response;
                }
            }
            
            return new Response(Status::NOT_FOUND);
        };
    }
    
    public function handleRequest(Request $request): Promise
    {
        return call($this->callable, $request);
    }
}
