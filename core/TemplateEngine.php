<?php

class TemplateEngine
{
    private string $templateDir;
    private string $cacheDir;
    private array $vars = [];
    private array $globals = [];
    private bool $disableCache = false;

    public function __construct(string $templateDir, string $cacheDir, array $config = [])
    {
        $config = require __DIR__ . '/../config/config.php';
        $this->templateDir = rtrim($templateDir, '/');
        $this->cacheDir = rtrim($cacheDir, '/');

        if (!is_dir($this->cacheDir))
        {
            mkdir($this->cacheDir, 0755, true);
        }

        // Automatic globals
        $this->globals = [
            'site_name' => $config['site']['name'],
            'current_year' => date('Y'),
            'is_logged_in' => isset($_SESSION['user_id']),
            'build_version' => $config['site']['version'],
            'age_requirement' => $config['profile']['years'],
            'site_user' => SessionManager::get('username', ''),
        ];

        // Disable cache if config set
        $this->disableCache = !empty($config['template']['disable_cache']);
        if ($this->disableCache)
        {
            $this->clearCache();
        }
    }

    public function assign(string $key, mixed $value): void
    {
        $this->vars[$key] = $value;
    }

    public function render(string $template): void
    {
        extract(array_merge($this->globals, $this->vars), EXTR_SKIP);

        include $this->compile($template);
    }

    private function compile(string $template): string
    {
        $templatePath = $this->templateDir . '/' . $template;

        if (!file_exists($templatePath))
        {
            throw new RuntimeException("Template file not found: $templatePath");
        }

        $compiledPath = $this->cacheDir . '/' . str_replace(['/', '.'], '_', $template) . '.php';

        if (!file_exists($compiledPath) || filemtime($templatePath) > filemtime($compiledPath) || $this->disableCache)
        {
            $content = file_get_contents($templatePath);

            // --- Unified template parsing ---
            $content = preg_replace_callback(
                '/\{\s*\$([a-zA-Z0-9_]+)\s*\}|' . // {$var}
                '\{([a-zA-Z_][a-zA-Z0-9_]*)\((.*?)\)\}|' . // {function($var)}
                '\{if\s+(.+?)\}|' . // {if condition}
                '\{else\sif\s+(.+?)\}|' . // {else if condition}
                '\{else\}|' . // {else}
                '\{\/if\}|' . // {/if}
                '\{foreach\s+(.+?)\s+as\s+(.+?)\}|' . // {foreach ... as ...}
                '\{\/foreach\}|' . // {/foreach}
                '\{for\s+(.+?)\}|' . // {for ...}
                '\{\/for\}|' . // {/for}
                '\{while\s+(.+?)\}|' . // {while ...}
                '\{\/while\}|' . // {/while}
                '\{block\s+name="(.+?)"\}|' . // {block name="..."}
                '\{\/block\}|' . // {/block}
                '\{include\s+file="(.+?)"\}|' . // {include file="..."}
                '\{raw\s+\$([a-zA-Z0-9_]+)\}/s', // {raw $var}
                function($m) {
                    if (!empty($m[1])) return '<?= strip_tags((string)($' . $m[1] . ' ?? ""), "<b><i><u><strong><em>") ?>';
                    if (!empty($m[2])) return '<?= htmlspecialchars(' . $m[2] . '(' . trim($m[3]) . '), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>';
                    if (!empty($m[4])) return "\n<?php if (" . str_replace('||', ' || ', trim($m[4])) . "): ?>";
                    if (!empty($m[5])) return "\n<?php elseif (" . trim($m[5]) . "): ?>";
                    if (isset($m[0]) && $m[0] === '{else}') return "\n<?php else: ?>";
                    if (isset($m[0]) && $m[0] === '{/if}') return "\n<?php endif; ?>";
                    if (!empty($m[6])) return "\n<?php foreach (" . $m[6] . " as " . $m[7] . "): ?>";
                    if (isset($m[0]) && $m[0] === '{/foreach}') return "\n<?php endforeach; ?>";
                    if (!empty($m[8])) return "\n<?php for (" . $m[8] . "): ?>";
                    if (isset($m[0]) && $m[0] === '{/for}') return "\n<?php endfor; ?>";
                    if (!empty($m[9])) return "\n<?php while (" . $m[9] . "): ?>";
                    if (isset($m[0]) && $m[0] === '{/while}') return "\n<?php endwhile; ?>";
                    if (!empty($m[10])) return "\n<?php /* block: " . $m[10] . " */ ?>";
                    if (isset($m[0]) && $m[0] === '{/block}') return "\n<?php /* endblock */ ?>";
                    if (!empty($m[11])) {
                        $includedTemplate = $this->templateDir . '/' . $m[11];
                        return file_exists($includedTemplate) ? "\n<?php include '" . addslashes($this->compile($m[11])) . "'; ?>" : '';
                    }

                    if (!empty($m[12]))
                    {
                        return '<?= $' . $m[12] . ' ?>';
                    }

                    return $m[0]; // fallback
                },
                $content
            );

            // --- Cleanup ---
            $content = preg_replace('/[ \t]+$/m', '', $content); // trailing spaces
            $content = preg_replace("/\n{3,}/", "\n\n", $content); // max 2 blank lines

            file_put_contents($compiledPath, $content);
        }

        return $compiledPath;
    }

    public function clearCache(): void
    {
        foreach (glob($this->cacheDir . '/*.php') as $file)
        {
            is_file($file) && unlink($file);
        }
    }
}
