<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Small, safe Markdown renderer for the bundled integration guides.
 * Everything is HTML-escaped; supported: #-headings, paragraphs, fenced code, lists, tables,
 * `code`, **bold**, [links](https://...). The directive <!-- include: path lang=php -->
 * inserts a repository file as a code block so guides and example files never drift apart.
 */
final class Markdown
{
    public function __construct(private string $root, private array $replacements = [])
    {
    }

    public function render(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $markdown = preg_replace_callback(
            '/<!--\s*include:\s*([A-Za-z0-9_\/.\-\[\]]+)(?:\s+lang=([a-z]+))?\s*-->/',
            function (array $m): string {
                $path = $m[1];
                $real = realpath($this->root . '/' . $path);
                $base = realpath($this->root . '/integrations');
                if ($real === false || $base === false || !str_starts_with($real, $base) || !is_file($real)) {
                    return "```\n(missing file: {$path})\n```";
                }
                $lang = $m[2] ?? '';
                return "```{$lang}\n" . rtrim((string) file_get_contents($real)) . "\n```";
            },
            $markdown
        ) ?? $markdown;
        $markdown = strtr($markdown, $this->replacements);

        $lines = explode("\n", $markdown);
        $html = '';
        $i = 0;
        $n = count($lines);
        while ($i < $n) {
            $line = $lines[$i];
            if (preg_match('/^```(\w*)\s*$/', $line, $m)) {
                $code = [];
                $i++;
                while ($i < $n && !preg_match('/^```\s*$/', $lines[$i])) {
                    $code[] = $lines[$i];
                    $i++;
                }
                $i++;
                $lang = $m[1] !== '' ? ' data-lang="' . e($m[1]) . '"' : '';
                $html .= '<div class="code-block"><button type="button" class="btn btn-small copy-btn" data-copy-next>Copy</button>'
                    . '<pre' . $lang . '><code>' . e(implode("\n", $code)) . '</code></pre></div>' . "\n";
                continue;
            }
            if (preg_match('/^(#{1,4})\s+(.*)$/', $line, $m)) {
                $level = min(4, strlen($m[1]) + 1);
                $html .= "<h{$level}>" . $this->inline($m[2]) . "</h{$level}>\n";
                $i++;
                continue;
            }
            if (preg_match('/^\s*([-*]|\d+\.)\s+/', $line)) {
                $ordered = (bool) preg_match('/^\s*\d+\./', $line);
                $tag = $ordered ? 'ol' : 'ul';
                $html .= "<{$tag}>";
                while ($i < $n && preg_match('/^\s*([-*]|\d+\.)\s+(.*)$/', $lines[$i], $m)) {
                    $html .= '<li>' . $this->inline($m[2]) . '</li>';
                    $i++;
                }
                $html .= "</{$tag}>\n";
                continue;
            }
            if (str_starts_with(trim($line), '|')) {
                $rows = [];
                while ($i < $n && str_starts_with(trim($lines[$i]), '|')) {
                    $rows[] = $lines[$i];
                    $i++;
                }
                $html .= $this->table($rows);
                continue;
            }
            if (trim($line) === '') {
                $i++;
                continue;
            }
            $para = [];
            while ($i < $n && trim($lines[$i]) !== '' && !preg_match('/^(```|#{1,4}\s|\s*([-*]|\d+\.)\s|\|)/', $lines[$i])) {
                $para[] = trim($lines[$i]);
                $i++;
            }
            $html .= '<p>' . $this->inline(implode(' ', $para)) . "</p>\n";
        }
        return $html;
    }

    private function table(array $rows): string
    {
        $cells = static fn (string $row): array => array_map('trim', explode('|', trim(trim($row), '|')));
        $out = '<div class="table-wrap"><table class="table"><thead><tr>';
        foreach ($cells($rows[0]) as $c) {
            $out .= '<th scope="col">' . $this->inline($c) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach (array_slice($rows, 2) as $row) {
            $out .= '<tr>';
            foreach ($cells($row) as $c) {
                $out .= '<td>' . $this->inline($c) . '</td>';
            }
            $out .= '</tr>';
        }
        return $out . "</tbody></table></div>\n";
    }

    private function inline(string $text): string
    {
        $parts = preg_split('/(`[^`]+`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $out = '';
        foreach ($parts as $part) {
            if (strlen($part) > 1 && $part[0] === '`' && str_ends_with($part, '`')) {
                $out .= '<code>' . e(substr($part, 1, -1)) . '</code>';
                continue;
            }
            $s = e($part);
            $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s) ?? $s;
            $s = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', static function (array $m): string {
                return '<a href="' . $m[2] . '" rel="noopener noreferrer" target="_blank">' . $m[1] . '</a>';
            }, $s) ?? $s;
            $out .= $s;
        }
        return $out;
    }
}
