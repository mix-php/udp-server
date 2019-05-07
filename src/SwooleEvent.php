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
     * ManagerStop
     */
    const MANAGER_STOP = 'managerStop';

    /**
     * WorkerStart
     */
    const WORKER_START = 'workerStart';

    /**
     * WorkerStop
     */
    const WORKER_STOP = 'workerStop';

    /**
     * Packet
     */
    const PACKET = 'packet';

}
