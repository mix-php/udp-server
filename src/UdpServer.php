<?php

namespace Mix\Udp\Server;

use Mix\Udp\Server\Exception\BindException;
use Mix\Udp\Server\Exception\SendException;
use Swoole\Coroutine\Socket;
use Mix\Concurrent\Coroutine;

/**
 * Class UdpServer
 * @package Mix\Udp\Server
 * @author liu,jian <coder.keda@gmail.com>
 */
class UdpServer
{

    /**
     * @var int
     */
    public $domain = AF_INET;

    /**
     * @var string
     */
    public $address = '127.0.0.1';

    /**
     * @var int
     */
    public $port = 9504;

    /**
     * @var array
     */
    protected $options = [];

    /**
     * @var Socket
     */
    public $swooleSocket;

    /**
     * @var callable
     */
    protected $handler;

    /**
     * UdpServer constructor.
     * @param int $domain
     * @param string $address
     * @param int $port
     */
    public function __construct(int $domain, string $address, int $port)
    {
        $this->domain       = $domain;
        $this->address      = $address;
        $this->port         = $port;
        $this->swooleSocket = new Socket($domain, SOCK_DGRAM, 0);
    }

    /**
     * Handle
     * @param callable $callback
     */
    public function handle(callable $callback)
    {
        $this->handler = $callback;
    }

    /**
     * Start
     */
    public function start()
    {
        $socket = $this->swooleSocket;
        $result = $socket->bind($this->address, $this->port);
        if (!$result) {
            throw new BindException($socket->errMsg, $socket->errCode);
        }
        while (true) {
            $peer = null;
            $data = $socket->recvfrom($peer);
            if ($socket->errCode == 104) { // shutdown
                return;
            }
            if ($data === false) {
                continue;
            }
            Coroutine::create($this->handler, $data, $peer);
        }
    }

    /**
     * Send
     * @param string $data
     * @param int $port
     * @param string $address
     * @return bool
     */
    public function send(string $data, int $port, string $address)
    {
        $len  = strlen($data);
        $size = $this->swooleSocket->sendto($address, $port, $data);
        if ($size === false) {
            throw new SendException($this->swooleSocket->errMsg, $this->swooleSocket->errCode);
        }
        if ($len !== $size) {
            throw new SendException('The sending data is incomplete for unknown reasons.');
        }
        return true;
    }

    /**
     * Shutdown
     * @return bool
     */
    public function shutdown()
    {
        return $this->swooleSocket->close();
    }

}
