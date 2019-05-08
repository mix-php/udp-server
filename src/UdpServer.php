<?php

namespace Mix\Udp\Server;

use Mix\Core\Bean\AbstractObject;
use Mix\Core\Coroutine;
use Mix\Helper\ProcessHelper;
use Mix\Udp\ClientInfo;

/**
 * Class UdpServer
 * @package Mix\Udp\Server
 * @author liu,jian <coder.keda@gmail.com>
 */
class UdpServer extends AbstractObject
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
     * 应用配置文件
     * @var string
     */
    public $configFile = '';

    /**
     * 运行参数
     * @var array
     */
    public $setting = [];

    /**
     * 服务名称
     * @var string
     */
    const SERVER_NAME = 'mix-udpd';

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
        // 开启后，PDO 协程多次 prepare 才不会有 40ms 延迟
        'open_tcp_nodelay'    => true,
        // 进程的最大任务数
        'max_request'         => 0,
        // 主进程启动事件回调
        'event_start'         => null,
        // 管理进程启动事件回调
        'event_manager_start' => null,
        // 管理进程停止事件回调
        'event_manager_stop'  => null,
        // 工作进程启动事件回调
        'event_worker_start'  => null,
        // 工作进程停止事件回调
        'event_worker_stop'   => null,
        // 监听数据事件回调
        'event_packet'        => null,
    ];

    /**
     * 运行参数
     * @var array
     */
    protected $_setting = [];

    /**
     * 服务器
     * @var \Swoole\WebSocket\Server
     */
    protected $_server;

    /**
     * 启动服务
     * @return bool
     */
    public function start()
    {
        // 初始化
        $this->_server = new \Swoole\Server($this->host, $this->port, SWOOLE_PROCESS, SWOOLE_SOCK_UDP);
        // 配置参数
        $this->_setting = $this->setting + $this->_defaultSetting;
        $this->_server->set($this->_setting);
        // 禁用内置协程
        $this->_server->set([
            'enable_coroutine' => false,
        ]);
        // 绑定事件
        $this->_server->on(SwooleEvent::START, [$this, 'onStart']);
        $this->_server->on(SwooleEvent::MANAGER_START, [$this, 'onManagerStart']);
        $this->_server->on(SwooleEvent::MANAGER_STOP, [$this, 'onManagerStop']);
        $this->_server->on(SwooleEvent::WORKER_START, [$this, 'onWorkerStart']);
        $this->_server->on(SwooleEvent::WORKER_STOP, [$this, 'onWorkerStop']);
        $this->_server->on(SwooleEvent::PACKET, [$this, 'onPacket']);
        // 欢迎信息
        $this->welcome();
        // 执行回调
        $this->_setting['event_start'] and call_user_func($this->_setting['event_start']);
        // 启动
        return $this->_server->start();
    }

    /**
     * 主进程启动事件
     * 仅允许echo、打印Log、修改进程名称，不得执行其他操作
     * @param \Swoole\Server $server
     */
    public function onStart(\Swoole\Server $server)
    {
        // 进程命名
        ProcessHelper::setProcessTitle(static::SERVER_NAME . ": master {$this->host}:{$this->port}");
    }

    /**
     * 管理进程启动事件
     * 可以使用基于信号实现的同步模式定时器swoole_timer_tick，不能使用task、async、coroutine等功能
     * @param \Swoole\Server $server
     */
    public function onManagerStart(\Swoole\Server $server)
    {
        try {

            // 进程命名
            ProcessHelper::setProcessTitle(static::SERVER_NAME . ": manager");
            // 执行回调
            $this->_setting['event_manager_start'] and call_user_func($this->_setting['event_manager_start']);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        } finally {
            // 清扫组件容器(仅同步模式, 协程会在xgo内清扫)
            if (!$this->_setting['enable_coroutine']) {
                \Mix::$app->cleanComponents();
            }
        }
    }

    /**
     * 管理进程停止事件
     * @param \Swoole\Server $server
     */
    public function onManagerStop(\Swoole\Server $server)
    {
        if ($this->_setting['enable_coroutine'] && Coroutine::id() == -1) {
            xgo(function () use ($server) {
                call_user_func([$this, 'onManagerStart'], $server);
            });
            return;
        }
        try {

            // 执行回调
            $this->_setting['event_manager_stop'] and call_user_func($this->_setting['event_manager_stop']);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        } finally {
            // 清扫组件容器(仅同步模式, 协程会在xgo内清扫)
            if (!$this->_setting['enable_coroutine']) {
                \Mix::$app->cleanComponents();
            }
        }
    }

    /**
     * 工作进程启动事件
     * @param \Swoole\Server $server
     * @param int $workerId
     */
    public function onWorkerStart(\Swoole\Server $server, int $workerId)
    {
        if ($this->_setting['enable_coroutine'] && Coroutine::id() == -1) {
            xgo(function () use ($server, $workerId) {
                call_user_func([$this, 'onWorkerStart'], $server, $workerId);
            });
            return;
        }
        try {

            // 进程命名
            if ($workerId < $server->setting['worker_num']) {
                ProcessHelper::setProcessTitle(static::SERVER_NAME . ": worker #{$workerId}");
            } else {
                ProcessHelper::setProcessTitle(static::SERVER_NAME . ": task #{$workerId}");
            }
            // 执行回调
            $this->_setting['event_worker_start'] and call_user_func($this->_setting['event_worker_start']);
            // 实例化App
            new \Mix\Udp\Application(require $this->configFile);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        } finally {
            // 清扫组件容器(仅同步模式, 协程会在xgo内清扫)
            if (!$this->_setting['enable_coroutine']) {
                \Mix::$app->cleanComponents();
            }
        }
    }

    /**
     * 工作进程停止事件
     * @param \Swoole\Server $server
     * @param int $workerId
     */
    public function onWorkerStop(\Swoole\Server $server, int $workerId)
    {
        if ($this->_setting['enable_coroutine'] && Coroutine::id() == -1) {
            xgo(function () use ($server, $workerId) {
                call_user_func([$this, 'onWorkerStart'], $server, $workerId);
            });
            return;
        }
        try {

            // 执行回调
            $this->_setting['event_worker_stop'] and call_user_func($this->_setting['event_worker_stop']);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
        } finally {
            // 清扫组件容器(仅同步模式, 协程会在xgo内清扫)
            if (!$this->_setting['enable_coroutine']) {
                \Mix::$app->cleanComponents();
            }
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
        if ($this->_setting['enable_coroutine'] && Coroutine::id() == -1) {
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
            $this->_setting['event_packet'] and call_user_func($this->_setting['event_packet'], true);

        } catch (\Throwable $e) {
            // 错误处理
            \Mix::$app->error->handleException($e);
            // 执行回调
            $this->_setting['event_packet'] and call_user_func($this->_setting['event_packet'], false);

        } finally {
            // 清扫组件容器(仅同步模式, 协程会在xgo内清扫)
            if (!$this->_setting['enable_coroutine']) {
                \Mix::$app->cleanComponents();
            }
        }
    }

    /**
     * 欢迎信息
     */
    protected function welcome()
    {
        $swooleVersion = swoole_version();
        $phpVersion    = PHP_VERSION;
        echo <<<EOL
                             _____
_______ ___ _____ ___   _____  / /_  ____
__/ __ `__ \/ /\ \/ /__ / __ \/ __ \/ __ \
_/ / / / / / / /\ \/ _ / /_/ / / / / /_/ /
/_/ /_/ /_/_/ /_/\_\  / .___/_/ /_/ .___/
                     /_/         /_/


EOL;
        println('Server         Name:      ' . static::SERVER_NAME);
        println('System         Name:      ' . strtolower(PHP_OS));
        println("PHP            Version:   {$phpVersion}");
        println("Swoole         Version:   {$swooleVersion}");
        println('Framework      Version:   ' . \Mix::$version);
        $this->_setting['max_request'] == 1 and println('Hot            Update:    enabled');
        $this->_setting['enable_coroutine'] and println('Coroutine      Mode:      enabled');
        println("Listen         Addr:      {$this->host}");
        println("Listen         Port:      {$this->port}");
        println('Reactor        Num:       ' . $this->_setting['reactor_num']);
        println('Worker         Num:       ' . $this->_setting['worker_num']);
        println("Configuration  File:      {$this->configFile}");
    }

}
