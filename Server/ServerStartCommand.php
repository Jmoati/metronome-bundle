<?php

namespace Jmoati\MetronomeBundle\Server;

use Amp\Http\Server\HttpServer;

use Amp\Loop;
use Jmoati\MetronomeBundle\Routing\SymfonyRouting;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Amp\Socket\Server;

final class ServerStartCommand extends Command implements LoggerAwareInterface
{
    protected static $defaultName = 'server:start';
    
    use LoggerAwareTrait;
    
    public function __construct(
        private string $projectDir,
        private RequestHandler $requestHandler,
        private SymfonyRouting $symfonyRouting
    ) {
        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this
            ->setDescription('Start the Http server')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'The port to be listen', 1337);
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Loop::run(function () use ($input) {
            $sockets = [
                Server::listen(sprintf("0.0.0.0:%d", (int)$input->getOption("port"))),
                Server::listen(sprintf("[::]:%d", (int)$input->getOption("port"))),
            ];
            
            $server = new HttpServer(
                $sockets,
                $this->requestHandler,
                $this->logger
            );
            
            yield $server->start();
            
            Loop::onSignal(SIGINT, function (string $watcherId) use ($server) {
                
                Loop::cancel($watcherId);
                yield $server->stop();
    
                $this->logger->info("Bye Bye");
            });
        });
        
        return self::SUCCESS;
    }
}
