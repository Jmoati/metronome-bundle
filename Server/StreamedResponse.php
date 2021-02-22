<?php

namespace Jmoati\MetronomeBundle\Server;

use Symfony\Component\HttpFoundation\StreamedResponse as SfStreamedResponse;

class StreamedResponse extends SfStreamedResponse
{
    public function getCallback()
    {
        return $this->callback;
    }
    
    public function sendContent()
    {
        return $this;
    }
    
    public function sendHeaders()
    {
        return $this;
    }
}
