<?php

use Amp\Parallel\Sync\Channel;
use App\Kernel;
use Jmoati\MetronomeBundle\Server\Response;
use Jmoati\MetronomeBundle\Server\StreamedRequest;
use Jmoati\MetronomeBundle\Server\StreamedResponse;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\HttpFoundation\Request as SfRequest;
use Symfony\Component\HttpFoundation\Response as SfResponse;
use Jmoati\MetronomeBundle\Server\Request;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use function Amp\call;

return function (Channel $channel): Generator
{
    register_shutdown_function(function () use ($channel) {
        $lastError = error_get_last();

        if (null === $lastError) {
            $channel->send('Worker die ... Do you use die() or exit() ?');
        } else {
            $channel->send(sprintf('%s (%s | line %d)', $lastError['message'], $lastError['file'], $lastError['line']));
        }

    });
    
    (new Dotenv())->bootEnv(__DIR__ . '/../../../../.env');
    
    if ($_SERVER['APP_DEBUG']) {
        umask(0000);
        Debug::enable();
    }
    
    $kernel = new Kernel($_SERVER['APP_ENV'], (bool)$_SERVER['APP_DEBUG']);
    
    while (true) {
        try {
            gc_collect_cycles();
            
            $sfRequest = new SfRequest();

            if ($request = yield $channel->receive()) {
                assert($request instanceof Request);

                $content = null;
                $lock = null;

                if ($request->contentPath) {
                    $store = new FlockStore($kernel->getProjectDir().'/var/tmp/stores');
                    $lock = (new LockFactory($store))->createLock($request->contentPath, 1800);
                    $content = fopen($request->contentPath, 'r') ?: null;
                }

                $sfRequest = StreamedRequest::create(
                    $request->uri,
                    $request->method,
                    $request->parameters,
                    $request->cookies,
                    $request->files,
                    $request->server,
                    $content,
                    $lock
                );
            }
            
            $kernel->boot();
            
            try {
                $sfResponse = $kernel->handle($sfRequest);
            } catch (Exception $exception) {
                $sfResponse = new SfResponse((string)$exception);
            }
        } catch (Exception $exception) {
            $sfResponse = new SfResponse((string)$exception);
        }
    
        $kernel->terminate($sfRequest, $sfResponse);
        
        yield $channel->send(new Response(
            $sfResponse->getContent(),
            $sfResponse->headers,
            $sfResponse->getStatusCode()
        ));

        if ($sfResponse instanceof StreamedResponse) {
            yield call(function() use ($sfResponse, $channel) {
                $sfResponse->getCallback()($channel);
            });

            yield $channel->send(null);
        }
    }
};
