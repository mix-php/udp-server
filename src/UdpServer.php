<?php

namespace Mix\Udp\Server;

use Mix\Concurrent\Coroutine;
use Mix\Helper\ProcessHelper;
use Mix\Server\Event;
use Mix\Server\AbstractServer;

/**
 * Class UdpServer
 * @package Mix\Udp\Server
 * @author liu,jian <coder.keda@gmail.com>
 */
class UdpServer extends AbstractServer
{

    /**
     * 主机
     * @var string
     */
    public $host = '127.0.0.1';

    /**
     * 端口
     * @var int
     */
    public $port = 9504;

    /**
     * 默认运行参数
     * @var array
     */
    protected $_defaultSetting = [
        // 开启协程
        'enable_coroutine'    => true,
        // 主进程事件处理线程数
        'reactor_num'         => 8,
        // 工作进程数
        'worker_num'          => 8,
        // 任务进程数
        'task_worker_num'     => 0,
        // PID 文件
        'pid_file'            => '/var/run/mix-udpd.pid',
        // 日志文件路径
        'log_file'            => '/tmp/mix-udpd.log',
        // 异步安全重启
        'reload_async'        => true,
        // 退出等待时间
        'max_wait_time'       => 60,
        // 进程的最大任务数
        'max_request'         => 0,
        // 主进程启动事件回调
        'hook_start'          => null,
        // 主进程停止事件回调
        'hook_shutdown'       => null,
        // 管理进程启动事件回调
        'hook_manager_start'  => null,
        // 工作进程错误事件
        'hook_worker_error'   => null,
        // 管理进程停止事件回调
        'hook_manager_stop'   => null,
        // 工作进程启动事件回调
        'hook_worker_start'   => null,
        // 工作进程停止事件回调
        'hook_worker_stop'    => null,
        // 工作进程退出事件回调
        'hook_worker_exit'    => null,
        // 监听数据成功回调
        'hook_packet_success' => null,
        // 监听数据错误回调
        'hook_packet_error'   => null,
    ];

    /**
     * 启动服务
     * @return bool
     */
    public function start()
    {
        // 初始化
        $this->server = new \Swoole\Server($this->host, $this->port, SWOOLE_PROCESS, SWOOLE_SOCK_UDP);
        // 配置参数
        $this->setting += $this->_defaultSetting;
        $this->server->set($this->setting);
        // 覆盖参数
        $this->server->set([
            'enable_coroutine' => false, // 关闭默认协程，回调中有手动开启支持上下文的协程
        ]);
        // 绑定事件
        $this->server->on(Event::START, [$this, 'onStart']);
        $this->server->on(Event::SHUTDOWN, [$this, 'onShutdown']);
        $this->server->on(Event::MANAGER_START, [$this, 'onManagerStart']);
        $this->server->on(Event::WORKER_ERROR, [$this, 'onWorkerError']);
        $this->server->on(Event::MANAGER_STOP, [$this, 'onManagerStop']);
        $this->server->on(Event::WORKER_START, [$this, 'onWorkerStart']);
        $this->server->on(Event::WORKER_STOP, [$this, 'onWorkerStop']);
        $this->server->on(Event::WORKER_EXIT, [$this, 'onWorkerExit']);
        $this->server->on(Event::PACKET, [$this, 'onPacket']);
        // 欢迎信息
        $this->welcome();
        // 执行回调
        $this->setting['hook_start'] and call_user_func($this->setting['hook_start'], $this->server);
        // 启动
        return $this->server->start();
    }

    /**
     * 工作进程启动事件
     * @param \Swoole\Server $server
     * @param int $workerId
     */
    public function onWorkerStart(\Swoole\Server $server, int $workerId)
    {
        try {

            // 进程命名
            if ($workerId < $server->setting['worker_num']) {
                ProcessHelper::setProcessTitle($this->name . ": worker #{$workerId}");
            } else {
                ProcessHelper::setProcessTitle($this->name . ": task #{$workerId}");
            }
            // 执行回调
            $this->setting['hook_worker_start'] and call_user_func($this->setting['hook_worker_start'], $server);
            // 实例化App
            new \Mix\Udp\Application($this->config);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        }
    }

    /**
     * 监听数据事件
     * @param \Swoole\Server $server
     * @param string $data
     * @param array $clientInfo
     */
    public function onPacket(\Swoole\Server $server, string $data, array $clientInfo)
    {
        if ($this->setting['enable_coroutine'] && Coroutine::id() == -1) {
            xgo(function () use ($server, $data, $clientInfo) {
                call_user_func([$this, 'onPacket'], $server, $data, $clientInfo);
            });
            return;
        }
        try {

            // 前置初始化
            \Mix::$app->udp->beforeInitialize($server);
            // 处理消息
            \Mix::$app->runPacket(
                \Mix::$app->udp,
                $data,
                $clientInfo
            );
            // 执行回调
            $this->setting['hook_packet_success'] and call_user_func($this->setting['hook_packet_success'], $server, $clientInfo);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
            // 执行回调
            $this->setting['hook_packet_error'] and call_user_func($this->setting['hook_packet_error'], $server, $clientInfo);

        } finally {
            // 清扫组件容器(仅同步模式, 协程会在xgo内清扫)
            if (!$this->setting['enable_coroutine']) {
                \Mix::$app->cleanComponents();
            }
        }
    }

}
