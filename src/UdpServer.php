<?php

namespace Mix\Udp\Server;

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
     * @var string
     */
    public $host = '127.0.0.1';

    /**
     * @var int
     */
    public $port = 9504;

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
     * @param Socket $socket
     * @param string $host
     * @param int $port
     */
    public function __construct(string $host, int $port)
    {
        $this->host         = $host;
        $this->port         = $port;
        $this->swooleSocket = new Socket(AF_INET, SOCK_DGRAM, 0);
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
        $socket->bind($this->host, $this->port);
        while (true) {
            $peer = null;
            $data = $socket->recvfrom($peer);
            if ($socket->errCode == 104) { // shutdown
                return;
            }
            if ($data === false) {
                continue;
            }
            Coroutine::create($this->handler, $socket, $data, $peer);
        }
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
