<?php
// +----------------------------------------------------------------------
// | 商城系统 [ 致力于通过产品和服务，帮助商家高效化开拓市场 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2017~2025 https://www.example.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed 这不是一个自由软件，不允许对程序代码以任何形式任何目的的再发行
// +----------------------------------------------------------------------
// | Author: 项目团队 <admin@example.com>
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\timer\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Event;
use Workerman\Worker;

/**
 * 定时器 (Workerman)
 * 用于执行系统里的定时任务, 例如自动确认收货
 * 使用方法: 打开命令行 - 执行命令: php think timer start
 * Class Timer
 * @package app\common\command
 */
class Timer extends Command
{
    private const WORKERMAN_RUNTIME_DIRECTORY = 'workerman';
    private const WORKERMAN_FILE_PREFIX = 'timer';

    // 定时器句柄/ID
    protected $timer;

    // 时间间隔 (单位: 秒, 默认1秒)
    protected int $interval = 1;

    protected function configure()
    {
        // 指令配置
        $this->setName('timer')
            ->addArgument('status', Argument::REQUIRED, 'start/stop/reload/status/connections')
            ->addOption('d', null, Option::VALUE_NONE, 'daemon（守护进程）方式启动')
            ->addOption('i', null, Option::VALUE_OPTIONAL, '多长时间执行一次')
            ->setDescription('start/stop/restart 定时任务');
    }

    protected function init(Input $input, Output $output)
    {
        global $argv;

        if ($input->hasOption('i'))
            $this->interval = (int)$input->getOption('i');

        $argv[1] = $input->getArgument('status') ?: 'start';
        if ($input->hasOption('d')) {
            $argv[2] = '-d';
        } else {
            unset($argv[2]);
        }
    }

    /**
     * 创建定时器
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     */
    protected function execute(Input $input, Output $output)
    {
        $this->init($input, $output);
        $this->configureWorkerRuntimePaths();
        // 创建定时器任务
        $worker = new Worker();
        $worker->onWorkerStart = [$this, 'start'];
        Worker::runAll();
    }

    /**
     * Keep Workerman's mutable process files outside immutable releases.
     *
     * Workerman 3 creates a missing log with mode 0622, so the log is created
     * and tightened here before Worker::runAll() initializes the framework.
     */
    protected function configureWorkerRuntimePaths(): void
    {
        $runtimePath = rtrim(app()->getRuntimePath(), '/\\');
        if ($runtimePath === '') {
            throw new \RuntimeException('Timer runtime path is empty');
        }

        if (!is_dir($runtimePath)
            && !@mkdir($runtimePath, 0700, true)
            && !is_dir($runtimePath)
        ) {
            throw new \RuntimeException("Unable to create Timer runtime root: {$runtimePath}");
        }

        $runtimeRoot = realpath($runtimePath);
        if ($runtimeRoot === false || !is_dir($runtimeRoot)) {
            throw new \RuntimeException("Unable to resolve Timer runtime root: {$runtimePath}");
        }
        if (!is_writable($runtimeRoot)) {
            throw new \RuntimeException("Timer runtime root is not writable: {$runtimeRoot}");
        }

        $workermanDirectory = $runtimeRoot . DIRECTORY_SEPARATOR . self::WORKERMAN_RUNTIME_DIRECTORY;
        if (is_link($workermanDirectory)) {
            throw new \RuntimeException("Timer Workerman runtime directory must not be a symlink: {$workermanDirectory}");
        }
        if (file_exists($workermanDirectory) && !is_dir($workermanDirectory)) {
            throw new \RuntimeException("Timer Workerman runtime path is not a directory: {$workermanDirectory}");
        }
        if (!is_dir($workermanDirectory)
            && !@mkdir($workermanDirectory, 0700)
            && !is_dir($workermanDirectory)
        ) {
            throw new \RuntimeException("Unable to create Timer Workerman runtime directory: {$workermanDirectory}");
        }

        clearstatcache(true, $workermanDirectory);
        $resolvedDirectory = realpath($workermanDirectory);
        if (is_link($workermanDirectory) || $resolvedDirectory !== $workermanDirectory) {
            throw new \RuntimeException("Unsafe Timer Workerman runtime directory: {$workermanDirectory}");
        }
        if (!@chmod($workermanDirectory, 0700)) {
            throw new \RuntimeException("Unable to secure Timer Workerman runtime directory: {$workermanDirectory}");
        }
        clearstatcache(true, $workermanDirectory);
        if (!is_writable($workermanDirectory)) {
            throw new \RuntimeException("Timer Workerman runtime directory is not writable: {$workermanDirectory}");
        }

        $pidFile = $workermanDirectory . DIRECTORY_SEPARATOR . self::WORKERMAN_FILE_PREFIX . '.pid';
        $logFile = $workermanDirectory . DIRECTORY_SEPARATOR . self::WORKERMAN_FILE_PREFIX . '.log';
        $this->assertSafeExistingRuntimeFile($pidFile, 'PID');
        $this->prepareRuntimeLogFile($logFile);

        Worker::$pidFile = $pidFile;
        Worker::$logFile = $logFile;
    }

    private function assertSafeExistingRuntimeFile(string $path, string $label): void
    {
        if (is_link($path)) {
            throw new \RuntimeException("Timer Workerman {$label} file must not be a symlink: {$path}");
        }
        if (!file_exists($path)) {
            return;
        }
        if (!is_file($path)) {
            throw new \RuntimeException("Timer Workerman {$label} path is not a regular file: {$path}");
        }
        if (!is_readable($path) || !is_writable($path)) {
            throw new \RuntimeException("Timer Workerman {$label} file is not readable and writable: {$path}");
        }
    }

    private function prepareRuntimeLogFile(string $logFile): void
    {
        $this->assertSafeExistingRuntimeFile($logFile, 'log');
        if (!file_exists($logFile)) {
            $handle = @fopen($logFile, 'x');
            if ($handle !== false) {
                fclose($handle);
            }

            // A concurrent creator is acceptable only when it produced the
            // regular, non-symlink file required by the same contract.
            $this->assertSafeExistingRuntimeFile($logFile, 'log');
            if (!file_exists($logFile)) {
                throw new \RuntimeException("Unable to create Timer Workerman log file: {$logFile}");
            }
        }

        if (!@chmod($logFile, 0600)) {
            throw new \RuntimeException("Unable to secure Timer Workerman log file: {$logFile}");
        }
        clearstatcache(true, $logFile);
        $permissions = fileperms($logFile);
        if ($permissions === false || ($permissions & 0777) !== 0600) {
            throw new \RuntimeException("Timer Workerman log file has unsafe permissions: {$logFile}");
        }

        $handle = @fopen($logFile, 'ab');
        if ($handle === false) {
            throw new \RuntimeException("Timer Workerman log file is not writable: {$logFile}");
        }
        fclose($handle);
    }

    /**
     * 定时器执行的内容
     * @return false|int
     */
    public function start()
    {
        // 每隔n秒执行一次
        return $this->timer = \Workerman\Lib\Timer::add($this->interval, function () {
            try {
                // 这里执行系统预设的定时任务事件
                echo 'timer...' . PHP_EOL;
                Event::trigger('StoreTask');
            } catch (\Throwable $e) {
                echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
                $this->stop();
            }
        });
    }

    /**
     * 停止/删除定时器
     * @return bool
     */
    public function stop(): bool
    {
        return \Workerman\Lib\Timer::del($this->timer);
    }
}
