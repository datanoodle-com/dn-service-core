<?php

namespace DataNoodle\Tests\unit;

use DataNoodle\Service;

class AbstractService extends Service
{

    protected $exchange;

    protected $queue;

    public function processSuccess()
    {
    }

    public function setHost()
    {
        $this->host = 'localhost';
    }

    public function setPort()
    {
        $this->port = '5672';
    }

    public function setUser()
    {
        $this->user = 'guest';
    }

    public function setPass()
    {
        $this->pass = 'guest';
    }

    public function getExchange()
    {
        return $this->exchange;
    }

    public function setExchange($exchange, $topic = 'topic', $passive = false, $durable = true, $auto_delete = false)
    {
        $this->exchange = $exchange;
    }

    public function getQueue()
    {
        return $this->queue;
    }

    public function getName()
    {
        return $this->name;
    }
}
