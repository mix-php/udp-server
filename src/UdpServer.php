<?php

namespace Mix\Udp\Server;

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
     * @var string
     */
    public $host = '127.0.0.1';

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
     * Send to
     * @param string $host
     * @param int $port
     * @param string $data
     * @return bool
     */
    public function sendTo(string $host, int $port, string $data)
    {
        $len  = strlen($data);
        $size = $this->swooleSocket->sendTo($host, $port, $data);
        if ($size === false) {
            throw new SendException($this->swooleSocket->errMsg, $this->swooleSocket->errCode);
        }
        if ($len !== $size) {
            throw new SendException('The sending data is incomplete, it may be that the socket has been closed by the peer.');
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
