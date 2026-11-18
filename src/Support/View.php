<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Plain PHP templates. Templates must escape every dynamic value with e().
 */
final class View
{
    /** @var array<string,mixed> shared variables */
    private array $shared = [];

    public function __construct(private string $templateDir)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = $this->renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }
        return $this->renderFile($layout, $data + ['content' => $content]);
    }

    public function partial(string $template, array $data = []): string
    {
        return $this->renderFile($template, $data);
    }

    private function renderFile(string $template, array $data): string
    {
        $file = $this->templateDir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Template not found: ' . $template);
        }
        $vars = $data + $this->shared;
        $vars['view'] = $this;
        return (static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string) ob_get_clean();
        })($file, $vars);
    }
}
