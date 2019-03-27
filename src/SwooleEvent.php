<?php

namespace Mix\Udp\Server;

/**
 * Class SwooleEvent
 * @package Mix\Udp\Server
 * @author liu,jian <coder.keda@gmail.com>
 */
class SwooleEvent
{

    /**
     * Start
     */
    const START = 'start';

    /**
     * ManagerStart
     */
    const MANAGER_START = 'managerStart';

    /**
     * WorkerStart
     */
    const WORKER_START = 'workerStart';

    /**
     * Packet
     */
    const PACKET = 'packet';

}
