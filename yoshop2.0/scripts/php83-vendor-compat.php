<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);

$replacements = [
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
