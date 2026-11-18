<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @var array<string,string> */
    public array $headers = [];

    /** @var array<string,array{value:string,expires:int,path:string,secure:bool}> cookies to set */
    public array $cookies = [];

    /** @var (callable():void)|null */
    public $stream = null;

    public function __construct(public string $body = '', public int $status = 200, array $headers = [])
    {
        foreach ($headers as $k => $v) {
            $this->headers[$k] = $v;
        }
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return new self((string) $body, $status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers);
    }

    public static function redirect(string $url, int $status = 303): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public static function download(string $body, string $filename, string $contentType): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'download';
        return new self($body, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
            foreach ($this->cookies as $name => $c) {
                setcookie($name, $c['value'], [
                    'expires' => $c['expires'],
                    'path' => $c['path'],
                    'secure' => $c['secure'],
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        }
        if ($this->stream !== null) {
            ($this->stream)();
            return;
        }
        echo $this->body;
    }
}
