<?php

namespace Jmoati\MetronomeBundle\Server;

use Amp\ByteStream\IteratorStream;
use Amp\Http\Server\Request as AmpRequest;
use Amp\Http\Server\Response as AmpResponse;
use Amp\Producer;
use Amp\Promise;
use Amp\Parallel\Context\Context;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;
use function Amp\ByteStream\pipe;
use function Amp\call;
use Amp\File\File;
use function Amp\File\openFile;
use function Amp\Parallel\Context\create;
use function Amp\File\deleteFile;

class Worker
{
    private Context $context;
    private bool $idle = false;
    private string $uuid;
    private ?File $file = null;
    private ?LockInterface $lock = null;
    
    public function __construct(
      private string $workerPath,
      private string $tmpPath,
    ) {
        $this->context = create($this->workerPath);
        $this->uuid = (string) uuid_create();

        (new Filesystem())->mkdir($this->getStoresDirectory());
        $store = new FlockStore($this->getStoresDirectory());
        $this->lock = (new LockFactory($store))->createLock($this->getContentFilepath(), 1800);
    }

    private function getStoresDirectory(): string
    {
        return $this->tmpPath.'/stores';
    }
    
    public function __destruct()
    {
        $this->cleanup();
    }

    private function getContentFilepath(): string
    {
        return $this->tmpPath.'/'.$this->uuid;
    }

    private function cleanup() {
        if (null !== $this->file) {
            $this->file->close();
            $this->file = null;
    
            deleteFile($this->getContentFilepath());
        }
    }

    public function isRunning(): bool
    {
        return $this->context->isRunning();
    }
    
    public function isIdle(): bool
    {
        return $this->idle;
    }
    
    public function shutdown(): Promise
    {
        return call(function () {
           $this->kill();
        });
    }
    
    public function kill()
    {
        $this->context->kill();
    }
    
    private function mapRequest(AmpRequest $request, ?string $contentPath): Request
    {
        $sfRequest = new Request(
            $request->getUri()->getPath() . '?' . $request->getUri()->getQuery(),
            $request->getMethod(),
            [],
            $request->getCookies(),
            [],
            $_SERVER + [
                'HTTP_HOST' => $request->getUri()->getHost() . ':' . $request->getUri()->getPort(),
            ],
            $contentPath,
            $request->getHeaders()
        );
        
        return $sfRequest;
    }
    
    public function handle(?AmpRequest $ampRequest): Promise
    {
        $this->idle = false;
        
        return call(function () use ($ampRequest) {
            $context = $this->context;
            $idle = &$this->idle;
            
            if (!$context->isRunning()) {
                yield $context->start();
            }
            
            $request = new Request('/');

            if (null !== $ampRequest) {
                if (false === $this->lock->acquire()) {
                    die('wtf ???');
                }

                $this->file = yield openFile($this->getContentFilepath(), "w+");
                $body = $ampRequest->getBody();
                $body->increaseSizeLimit(10 * 1024 ** 2 * 1024);

                $pipe = pipe($body, $this->file);

                $pipe->onResolve(function(){
                    $this->lock->release();
                });

                $request = $this->mapRequest($ampRequest, $this->getContentFilepath());
            }
    
            yield $context->send($request);
            $response = yield $context->receive();

            if (!$response instanceof Response) {
                $this->cleanup();
                return new AmpResponse(500, [], $response);
            }

            return new AmpResponse(
                $response->code,
                $response->headers->all(),
                new IteratorStream(new Producer(
                    function(callable $emit) use ($context, &$idle, $response) {
                if (false !== $response->content) {
                    yield $emit($response->content);
                } else {
                    while ($data = yield $context->receive()) {
                        yield $emit($data);
                    }
                }
                
                $idle = true;
                $this->cleanup();
            })));
        });
    }
}
