<?php

namespace Jmoati\MetronomeBundle\Routing;

use Amp\ByteStream\ResourceInputStream;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Promise;
use Jmoati\MetronomeBundle\Routing\Specs\RoutingInterface;
use Mimey\MimeTypes;
use function Amp\call;

final class StaticRouting implements RoutingInterface
{
    private MimeTypes $mimes;
    
    public function __construct(
        private string $projectDir
    ) {
        $this->mimes = new MimeTypes();
    }
    
    private function filename(Request $request): string|false
    {
        return realpath($this->projectDir.'/public'.$request->getUri()->getPath());
    }

    public function isSupported(Request $request): bool
    {
        $filename = $this->filename($request);
        
        return $filename && is_file($filename);
    }
    
    public function handle(Request $request): Promise
    {
        return call(function () use ($request) {
            $filename = $this->filename($request);
            $infos = pathinfo($filename);
            $resource = fopen($this->filename($request), 'r');
            $stream = new ResourceInputStream($resource);
            
            $response = new Response(
                Status::OK,
                ['content-type' => $this->mimes->getMimeType($infos['extension'])],
                $stream
            );
    
            $response->onDispose(function() use ($stream) {
                $stream->close();
            });
            
            return $response;
        });
    }
    
    public static function getDefaultPriority(): int
    {
        return 100;
    }
}