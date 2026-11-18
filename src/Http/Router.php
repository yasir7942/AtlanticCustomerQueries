<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Tiny router. Patterns use {name} for numeric segments, e.g. /inbox/{id}.
 */
final class Router
{
    /** @var list<array{method:string,regex:string,handler:array{0:class-string,1:string},options:array}> */
    private array $routes = [];

    public function add(string $method, string $pattern, array $handler, array $options = []): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[0-9]+)', $pattern) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'options' => $options];
    }

    /**
     * @return array{0:?array,1:array<string,string>,2:bool} [route, params, pathMatchedOtherMethod]
     */
    public function match(string $method, string $path): array
    {
        $other = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method && !($method === 'HEAD' && $route['method'] === 'GET')) {
                $other = true;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return [$route, $params, false];
        }
        return [null, [], $other];
    }
}
