<?php

namespace Jmoati\MetronomeBundle\Server;

use Amp\Http\Server\Request as AmpRequest;
use Amp\Http\Server\Response as AmpResponse;
use Amp\Http\Status;
use Amp\Process\StatusError;
use Amp\Promise;
use Error;
use SplObjectStorage;
use function Amp\call;
use function assert;

final class Pool
{
    private const DEFAULT_MAX_SIZE = 64;
    private const DEFAULT_MINIMUM_SIZE = 8;
    
    private bool $running = true;
    private SplObjectStorage $workers;
    private ?Promise $exitStatus = null;
    
    public function __construct(
        private string $workerPath,
        private string $tmpPath,
        private int $maxSize = self::DEFAULT_MAX_SIZE,
        private int $minSize = self::DEFAULT_MINIMUM_SIZE,

    ) {
        if ($maxSize < 0) {
            throw new Error("Maximum size must be a non-negative integer");
        }

        if ($minSize < 0 || $minSize > $maxSize) {
            throw new Error("Minimum size must be a non-negative integer greater than max size");
        }
        
        $this->workers = new SplObjectStorage;
        $this->warmup($this->minSize);
    }
    
    public function __destruct()
    {
        if ($this->isRunning()) {
            $this->kill();
        }
    }
    
    public function isRunning(): bool
    {
        return $this->running;
    }
    
    public function getMaxSize(): int
    {
        return $this->maxSize;
    }
    
    public function getMinSize(): int
    {
        return $this->minSize;
    }
    
    public function getWorkerCount(): int
    {
        return $this->workers->count();
    }
    
    public function warmup($count = self::DEFAULT_MINIMUM_SIZE): Promise {
        return call(function () use ($count) {
            for ($i = 0; $i < $count; ++$i) {
                $response = yield $this->handle(null);
                yield $response->getBody()->read(); // must be read te be release
            }
        });
    }
    
    public function shutdown(): Promise
    {
        if ($this->exitStatus) {
            return $this->exitStatus;
        }
        
        $this->running = false;
        
        $shutdowns = [];
        foreach ($this->workers as $worker) {
            assert($worker instanceof Worker);
            if ($worker->isRunning()) {
                $shutdowns[] = $worker->shutdown();
            }
        }
        
        return $this->exitStatus = Promise\all($shutdowns);
    }
    
    public function kill(): void
    {
        $this->running = false;
        
        foreach ($this->workers as $worker) {
            assert($worker instanceof Worker);
            if ($worker->isRunning()) {
                $worker->kill();
            }
        }
    }
    
    private function pull(): ?Worker
    {
        if (!$this->isRunning()) {
            throw new StatusError("The pool was shutdown");
        }
        
        if ($this->getWorkerCount() >= $this->maxSize) {
            return null;
        }
        
        if (($this->getWorkerCount() >= $this->minSize)) {
            foreach ($this->workers as $worker) {
                assert($worker instanceof Worker);
                if (false === $worker->isIdle() && false === $worker->isRunning()) {
                    $worker->shutdown();
                    $this->workers->detach($worker);
                    continue;
                }
                
                if ($worker->isIdle()) {
                    return $worker;
                }
            }
        }
    
        $worker = new Worker($this->workerPath, $this->tmpPath);
        $this->workers->attach($worker, 0);
        $this->workers[$worker] += 1;
        
        return $worker;
    }
    
    public function handle(?AmpRequest $request): Promise
    {
        $worker = $this->pull();
        
        if (null === $worker) {
            return call(function () {
               return new AmpResponse(Status::TOO_MANY_REQUESTS);
            });
        }
        
        return $worker->handle($request);
    }
}
