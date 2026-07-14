<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once $root . '/vendor/topthink/framework/src/helper.php';

use app\timer\command\Timer as TimerCommand;
use Workerman\Worker;

$sourcePath = $root . '/app/timer/command/Timer.php';
$source = file_get_contents($sourcePath);
if ($source === false) {
    throw new RuntimeException('unable to read Timer command source');
}

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    fwrite(STDOUT, "ok {$message}\n");
};

$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $entries = scandir($path);
    if ($entries !== false) {
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
};

$executeStart = strpos($source, 'protected function execute');
$executeEnd = $executeStart === false ? false : strpos($source, '/**', $executeStart);
$executeSource = $executeStart !== false && $executeEnd !== false
    ? substr($source, $executeStart, $executeEnd - $executeStart)
    : '';
$configurePosition = strpos($executeSource, '$this->configureWorkerRuntimePaths();');
$workerPosition = strpos($executeSource, '$worker = new Worker();');
$runAllPosition = strpos($executeSource, 'Worker::runAll();');
$expect($executeSource !== '', 'Timer execute method is discoverable');
$expect($configurePosition !== false, 'Timer execute configures Workerman runtime paths');
$expect($workerPosition !== false, 'Timer execute still creates the timer Worker');
$expect($runAllPosition !== false, 'Timer execute still calls Workerman runAll');
$expect(
    $configurePosition < $workerPosition && $configurePosition < $runAllPosition,
    'Workerman runtime paths are configured before Worker creation and runAll'
);
$expect(strpos($source, 'Worker::$pidFile = $pidFile;') !== false, 'Timer assigns the Workerman PID path');
$expect(strpos($source, 'Worker::$logFile = $logFile;') !== false, 'Timer assigns the Workerman log path');
$expect(strpos($source, 'Worker::$stdoutFile') === false, 'Timer leaves stdout attached for foreground systemd logging');
$expect(strpos($source, 'runtime directory is not writable') !== false, 'Timer reports an unwritable runtime directory clearly');

$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yoshop-timer-runtime-' . bin2hex(random_bytes(8));
$sharedRuntime = $fixture . DIRECTORY_SEPARATOR . 'shared-runtime';
$releaseRuntime = $fixture . DIRECTORY_SEPARATOR . 'release-runtime';
$localRuntime = $fixture . DIRECTORY_SEPARATOR . 'local-runtime';
$outside = $fixture . DIRECTORY_SEPARATOR . 'outside';
$symlinkRuntime = $fixture . DIRECTORY_SEPARATOR . 'symlink-runtime';

if (!mkdir($fixture, 0700) || !mkdir($sharedRuntime, 0700) || !mkdir($outside, 0700)) {
    throw new RuntimeException('unable to create isolated Timer runtime fixture');
}
if (!symlink($sharedRuntime, $releaseRuntime)) {
    throw new RuntimeException('unable to create production-style runtime symlink fixture');
}

$app = app();
$originalRuntimePath = $app->getRuntimePath();
$originalPidFile = Worker::$pidFile;
$originalLogFile = Worker::$logFile;
$originalStdoutFile = Worker::$stdoutFile;
$stdoutSentinel = $fixture . DIRECTORY_SEPARATOR . 'stdout-sentinel.log';

try {
    Worker::$stdoutFile = $stdoutSentinel;
    $app->setRuntimePath($releaseRuntime . DIRECTORY_SEPARATOR);

    $command = new TimerCommand();
    $configure = new ReflectionMethod(TimerCommand::class, 'configureWorkerRuntimePaths');
    $configure->setAccessible(true);
    $configure->invoke($command);

    $resolvedRoot = realpath($sharedRuntime);
    if ($resolvedRoot === false) {
        throw new RuntimeException('unable to resolve fixture runtime root');
    }
    $workermanDirectory = $resolvedRoot . DIRECTORY_SEPARATOR . 'workerman';
    $expectedPidFile = $workermanDirectory . DIRECTORY_SEPARATOR . 'timer.pid';
    $expectedLogFile = $workermanDirectory . DIRECTORY_SEPARATOR . 'timer.log';

    $expect(Worker::$pidFile === $expectedPidFile, 'PID file uses the canonical shared runtime path');
    $expect(Worker::$logFile === $expectedLogFile, 'log file uses the canonical shared runtime path');
    $expect(Worker::$stdoutFile === $stdoutSentinel, 'runtime setup does not redirect foreground stdout');
    $expect(is_dir($workermanDirectory) && !is_link($workermanDirectory), 'dedicated Workerman runtime directory is real');

    clearstatcache(true, $workermanDirectory);
    $directoryPermissions = fileperms($workermanDirectory);
    $expect(
        $directoryPermissions !== false && ($directoryPermissions & 0777) === 0700,
        'dedicated Workerman runtime directory is mode 0700'
    );
    $expect(!file_exists($expectedPidFile), 'runtime setup does not create a misleading PID file');
    $expect(is_file($expectedLogFile) && !is_link($expectedLogFile), 'Workerman log is pre-created as a regular file');

    clearstatcache(true, $expectedLogFile);
    $logPermissions = fileperms($expectedLogFile);
    $expect(
        $logPermissions !== false && ($logPermissions & 0777) === 0600,
        'Workerman log avoids the Workerman 0622 default mode'
    );

    chmod($expectedLogFile, 0666);
    clearstatcache(true, $expectedLogFile);
    $configure->invoke($command);
    clearstatcache(true, $expectedLogFile);
    $tightenedPermissions = fileperms($expectedLogFile);
    $expect(
        $tightenedPermissions !== false && ($tightenedPermissions & 0777) === 0600,
        'runtime setup tightens an existing unsafe Workerman log'
    );

    $app->setRuntimePath($localRuntime . DIRECTORY_SEPARATOR);
    $configure->invoke($command);
    $expect(
        Worker::$pidFile === $localRuntime . DIRECTORY_SEPARATOR . 'workerman' . DIRECTORY_SEPARATOR . 'timer.pid',
        'missing local runtime roots are created with the same stable PID contract'
    );
    $expect(
        is_file($localRuntime . DIRECTORY_SEPARATOR . 'workerman' . DIRECTORY_SEPARATOR . 'timer.log'),
        'missing local runtime roots receive the protected Workerman log'
    );

    if (!mkdir($symlinkRuntime, 0700)) {
        throw new RuntimeException('unable to create symlink-rejection fixture');
    }
    if (!symlink($outside, $symlinkRuntime . DIRECTORY_SEPARATOR . 'workerman')) {
        throw new RuntimeException('unable to create nested Workerman symlink fixture');
    }
    $app->setRuntimePath($symlinkRuntime . DIRECTORY_SEPARATOR);
    $rejected = false;
    try {
        $configure->invoke($command);
    } catch (RuntimeException $e) {
        $rejected = strpos($e->getMessage(), 'must not be a symlink') !== false;
    }
    $expect($rejected, 'nested Workerman runtime symlinks fail with a clear error');
    $expect(!file_exists($outside . DIRECTORY_SEPARATOR . 'timer.log'), 'symlink rejection does not write outside runtime');
} finally {
    $app->setRuntimePath($originalRuntimePath);
    Worker::$pidFile = $originalPidFile;
    Worker::$logFile = $originalLogFile;
    Worker::$stdoutFile = $originalStdoutFile;
    $removeTree($fixture);
}
