<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);

$replacements = [
    'vendor/workerman/workerman/Events/Select.php' => [
        [
            'search' => "class Select implements EventInterface\n{\n    /**\n     * All listeners for read/write event.\n",
            'replace' => "class Select implements EventInterface\n{\n    /**\n     * Normalize a float timeout in microseconds to a safe integer for PHP 8.3+\n     * stream_select/usleep no longer accept implicit float-to-int conversion.\n     */\n    protected function normalizeTimeout(\$timeout): int\n    {\n        if (!\\is_numeric(\$timeout)) {\n            return 0;\n        }\n        if (\$timeout <= 0) {\n            return 0;\n        }\n        return (int) max(1, min(100000000, round((float) \$timeout)));\n    }\n\n    /**\n     * All listeners for read/write event.\n",
        ],
        [
            'search' => "                \$select_timeout = (\$run_time - \\microtime(true)) * 1000000;\n                if( \$this->_selectTimeout > \$select_timeout ){ \n                    \$this->_selectTimeout = \$select_timeout;   \n                }  \n",
            'replace' => "                \$select_timeout = \$this->normalizeTimeout((\$run_time - \\microtime(true)) * 1000000);\n                if (\$this->_selectTimeout > \$select_timeout) {\n                    \$this->_selectTimeout = \$select_timeout;\n                }\n",
        ],
        [
            'search' => "            \$this->_selectTimeout = (\$next_run_time - \$time_now) * 1000000;\n",
            'replace' => "            \$this->_selectTimeout = \$this->normalizeTimeout((\$next_run_time - \$time_now) * 1000000);\n",
        ],
        [
            'search' => "                \$ret = stream_select(\$read, \$write, \$except, 0, \$this->_selectTimeout);\n",
            'replace' => "                \$ret = stream_select(\$read, \$write, \$except, 0, \$this->normalizeTimeout(\$this->_selectTimeout));\n",
        ],
        [
            'search' => "                usleep(\$this->_selectTimeout);\n",
            'replace' => "                usleep(\$this->normalizeTimeout(\$this->_selectTimeout));\n",
        ],
    ],
    'vendor/myclabs/php-enum/src/Enum.php' => [
        [
            'search' => "    public function jsonSerialize()\n",
            'replace' => "    #[\\ReturnTypeWillChange]\n    public function jsonSerialize()\n",
        ],
    ],
    'vendor/overtrue/socialite/src/HasAttributes.php' => [
        [
            'search' => "    public function offsetExists(\$offset)\n",
            'replace' => "    #[\\ReturnTypeWillChange]\n    public function offsetExists(\$offset)\n",
        ],
        [
            'search' => "    public function offsetGet(\$offset)\n",
            'replace' => "    #[\\ReturnTypeWillChange]\n    public function offsetGet(\$offset)\n",
        ],
        [
            'search' => "    public function offsetSet(\$offset, \$value)\n",
            'replace' => "    #[\\ReturnTypeWillChange]\n    public function offsetSet(\$offset, \$value)\n",
        ],
        [
            'search' => "    public function offsetUnset(\$offset)\n",
            'replace' => "    #[\\ReturnTypeWillChange]\n    public function offsetUnset(\$offset)\n",
        ],
    ],
    'vendor/overtrue/socialite/src/User.php' => [
        [
            'search' => "    public function jsonSerialize()\n",
            'replace' => "    #[\\ReturnTypeWillChange]\n    public function jsonSerialize()\n",
        ],
        [
            'search' => "    public function serialize()\n",
            'replace' => "    public function __serialize(): array\n    {\n        return \$this->attributes;\n    }\n\n    public function __unserialize(array \$data): void\n    {\n        \$this->attributes = \$data;\n    }\n\n    public function serialize()\n",
        ],
    ],
    'vendor/overtrue/socialite/src/AccessToken.php' => [
        [
            'search' => "    public function jsonSerialize()\n",
            'replace' => "    #[\\ReturnTypeWillChange]\n    public function jsonSerialize()\n",
        ],
    ],
    'vendor/easywechat-composer/easywechat-composer/src/Http/Response.php' => [
        [
            'search' => "    public function offsetExists(\$offset)\n",
            'replace' => "    #[\\ReturnTypeWillChange]\n    public function offsetExists(\$offset)\n",
        ],
        [
            'search' => "    public function offsetGet(\$offset)\n",
            'replace' => "    #[\\ReturnTypeWillChange]\n    public function offsetGet(\$offset)\n",
        ],
        [
            'search' => "    public function offsetSet(\$offset, \$value)\n",
            'replace' => "    #[\\ReturnTypeWillChange]\n    public function offsetSet(\$offset, \$value)\n",
        ],
        [
            'search' => "    public function offsetUnset(\$offset)\n",
            'replace' => "    #[\\ReturnTypeWillChange]\n    public function offsetUnset(\$offset)\n",
        ],
        [
            'search' => "    public function jsonSerialize()\n",
            'replace' => "    #[\\ReturnTypeWillChange]\n    public function jsonSerialize()\n",
        ],
    ],
    'vendor/overtrue/wechat/src/Kernel/Support/Collection.php' => [
        [
            'search' => "    public function jsonSerialize()\n",
            'replace' => "    #[\\ReturnTypeWillChange]\n    public function jsonSerialize()\n",
        ],
        [
            'search' => "    public function serialize()\n",
            'replace' => "    public function __serialize(): array\n    {\n        return ['items' => \$this->items];\n    }\n\n    public function __unserialize(array \$data): void\n    {\n        \$this->items = \$data['items'] ?? [];\n    }\n\n    public function serialize()\n",
        ],
        [
            'search' => "    public function getIterator()\n",
            'replace' => "    #[\\ReturnTypeWillChange]\n    public function getIterator()\n",
        ],
        [
            'search' => "    public function count()\n",
            'replace' => "    #[\\ReturnTypeWillChange]\n    public function count()\n",
        ],
        [
            'search' => "    public function offsetExists(\$offset)\n",
            'replace' => "    #[\\ReturnTypeWillChange]\n    public function offsetExists(\$offset)\n",
        ],
        [
            'search' => "    public function offsetUnset(\$offset)\n",
            'replace' => "    #[\\ReturnTypeWillChange]\n    public function offsetUnset(\$offset)\n",
        ],
        [
            'search' => "    public function offsetGet(\$offset)\n",
            'replace' => "    #[\\ReturnTypeWillChange]\n    public function offsetGet(\$offset)\n",
        ],
        [
            'search' => "    public function offsetSet(\$offset, \$value)\n",
            'replace' => "    #[\\ReturnTypeWillChange]\n    public function offsetSet(\$offset, \$value)\n",
        ],
    ],
    'vendor/league/flysystem/src/UnableToCreateDirectory.php' => [
        [
            'search' => '        $message = "Unable to create a directory at {$dirname}. ${errorMessage}";' . "\n",
            'replace' => '        $message = "Unable to create a directory at {$dirname}. {$errorMessage}";' . "\n",
        ],
    ],
    'vendor/league/flysystem/src/UnableToCheckFileExistence.php' => [
        [
            'search' => '        return new UnableToCheckFileExistence("Unable to check file existence for: ${path}", 0, $exception);' . "\n",
            'replace' => '        return new UnableToCheckFileExistence("Unable to check file existence for: {$path}", 0, $exception);' . "\n",
        ],
    ],
];

$patched = [];
$skipped = [];

foreach ($replacements as $relativePath => $ops) {
    $path = $projectRoot . DIRECTORY_SEPARATOR . $relativePath;

    if (!is_file($path)) {
        $skipped[] = $relativePath . ' (missing)';
        continue;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, "[compat:php83] failed to read {$relativePath}\n");
        exit(1);
    }

    $updated = $contents;
    $fileChanged = false;

    foreach ($ops as $op) {
        if (strpos($updated, $op['replace']) !== false) {
            continue;
        }

        if (strpos($updated, $op['search']) === false) {
            fwrite(STDERR, "[compat:php83] pattern not found in {$relativePath}\n");
            exit(1);
        }

        $updated = str_replace($op['search'], $op['replace'], $updated);
        $fileChanged = true;
    }

    if ($fileChanged) {
        if (file_put_contents($path, $updated) === false) {
            fwrite(STDERR, "[compat:php83] failed to write {$relativePath}\n");
            exit(1);
        }
        $patched[] = $relativePath;
    }
}

if ($patched) {
    echo '[compat:php83] patched: ' . implode(', ', $patched) . PHP_EOL;
} elseif ($skipped) {
    echo '[compat:php83] skipped: ' . implode(', ', $skipped) . PHP_EOL;
} else {
    echo "[compat:php83] no changes needed\n";
}
