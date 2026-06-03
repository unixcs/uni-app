<?php
// +----------------------------------------------------------------------
// | 服务单E2E curl助手
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\command;

use RuntimeException;

class ServiceOrderE2eHttpClient
{
    public function __construct(private string $evidenceDir = '')
    {
    }

    /** @param array<string, scalar|null> $headers */
    public function getJson(string $url, array $query = [], array $headers = []): array
    {
        if (!empty($query)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $this->request('GET', $url, [], $headers);
    }

    /** @param array<string, scalar|null> $headers */
    public function postJson(string $url, array $body = [], array $headers = []): array
    {
        return $this->request('POST', $url, $body, $headers);
    }

    /** @param array<string, scalar|null> $headers */
    private function request(string $method, string $url, array $body, array $headers): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('curl 初始化失败');
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $headerLines[] = $name . ': ' . $value;
        }
        if ($method === 'POST') {
            $headerLines[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headerLines,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        }
        $raw = curl_exec($ch);
        $stderr = curl_error($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $status = $raw === false ? 1 : 0;
        curl_close($ch);

        $decoded = json_decode((string)$raw, true);
        $ok = is_array($decoded) && (($decoded['status'] ?? null) === 200 || ($decoded['status'] ?? null) === 1 || ($decoded['code'] ?? null) === 1);
        if ($status !== 0 || !$ok) {
            $this->writeEvidence('http-' . date('YmdHis'), [
                'status' => $status,
                'http_status' => $httpStatus,
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'body' => $body,
                'stderr' => (string)$stderr,
                'raw' => (string)$raw,
                'data' => $decoded,
            ]);
        }
        return [
            'ok' => $status === 0 && $ok,
            'status' => $status,
            'http_status' => $httpStatus,
            'raw' => (string)$raw,
            'data' => $decoded,
            'error' => $stderr !== '' ? (string)$stderr : null,
            'method' => $method,
            'url' => $url,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function writeEvidence(string $name, array $payload): void
    {
        if ($this->evidenceDir === '') {
            return;
        }
        if (!is_dir($this->evidenceDir)) {
            mkdir($this->evidenceDir, 0777, true);
        }
        file_put_contents($this->evidenceDir . '/' . $name . '.json', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        file_put_contents($this->evidenceDir . '/' . $name . '.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7+2WcAAAAASUVORK5CYII='));
    }
}
