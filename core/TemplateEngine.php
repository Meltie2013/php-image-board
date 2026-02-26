<?php

/**
 * Lightweight template compiler and renderer for the gallery UI.
 *
 * Provides a small, framework-free templating layer with:
 * - Variable interpolation:      {$var}
 * - Function output helpers:     {function($var)}
 * - Basic control structures:    {if ...}{else}{/if}, {foreach ... as ...}{/foreach}, {for ...}{/for}, {while ...}{/while}
 * - Template includes:           {include file="partial.tpl"}
 * - Raw output escape hatch:     {raw $var}
 *
 * Templates are compiled to cached PHP files for performance. The compiled output
 * uses safe defaults (escaping, tag stripping with a limited allowlist) to reduce
 * XSS risk when rendering user-controlled values.
 *
 * Notes:
 * - Globals are automatically injected (site name, version, user info, etc.).
 * - Cache can be disabled via config for development; disabling also clears cache.
 * - This compiler intentionally supports a limited syntax to keep it auditable.
 */
class TemplateEngine
{
    private string $templateDir;
    private string $cacheDir;
    private array $vars = [];
    private array $globals = [];
    private bool $disableCache = false;

    // Template function whitelist (configurable via config/db settings)
    private array $allowedFunctions = [];
    private string $configHashPath = '';

    /**
     * Create a new TemplateEngine instance and prepare cache + automatic globals.
     *
     * Initializes:
     * - Template directory and cache directory paths
     * - Cache directory creation if missing
     * - Automatic global variables shared across all templates
     * - Cache behavior based on configuration (optionally clears cache when disabled)
     *
     * Note: This constructor loads the project config internally so templates can
     * reference consistent values (site name, version, pagination settings, etc.).
     */
    public function __construct(string $templateDir, string $cacheDir, array $config = [])
    {
        if (empty($config))
        {
            $config = SettingsManager::isInitialized() ? SettingsManager::getConfig() : (require __DIR__ . '/../config/config.php');
        }
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
            'displayed_comments' => $config['gallery']['comments_per_page'],
            'site_user_role' => SessionManager::get('user_role'),
        ];

        // Allowed template functions (whitelisted)
        $allowed = [];
        if (!empty($config['template']['allowed_functions']) && is_array($config['template']['allowed_functions']))
        {
            foreach ($config['template']['allowed_functions'] as $fn)
            {
                if (!is_string($fn)) continue;
                $fn = trim($fn);
                if ($fn === '') continue;

                // Only allow valid function name characters
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $fn))
                {
                    continue;
                }

                $allowed[] = $fn;
            }
        }

        // De-dupe and cap to avoid abuse
        $allowed = array_values(array_unique($allowed));
        if (count($allowed) > 64)
        {
            $allowed = array_slice($allowed, 0, 64);
        }

        $this->allowedFunctions = $allowed;

        // If template security settings change, clear cache to prevent stale compiled templates
        $this->configHashPath = $this->cacheDir . '/.template_config_hash';
        $hashPayload = [
            'allowed_functions' => $this->allowedFunctions,
        ];
        $currentHash = hash('sha256', json_encode($hashPayload));

        if (is_file($this->configHashPath))
        {
            $oldHash = trim((string)@file_get_contents($this->configHashPath));
            if ($oldHash !== $currentHash)
            {
                $this->clearCache();
            }
        }

        @file_put_contents($this->configHashPath, $currentHash);

        // Disable cache if config set
        $this->disableCache = !empty($config['template']['disable_cache']);
        if ($this->disableCache)
        {
            $this->clearCache();
        }
    }

    /**
     * Assign a template variable for use during render().
     *
     * Variables assigned here override globals when keys overlap (because vars are
     * merged after globals at render time).
     *
     * @param string $key Variable name used in templates (e.g., "images")
     * @param mixed $value Value to expose to the template
     */
    public function assign(string $key, mixed $value): void
    {
        $this->vars[$key] = $value;
    }

    /**
     * Render a template to the output buffer.
     *
     * Merges globals and assigned variables, extracts them into the template scope,
     * then includes the compiled PHP version of the template.
     *
     * @param string $template Template filename relative to templateDir (e.g., "gallery/index.tpl")
     */
    public function render(string $template): void
    {
        extract(array_merge($this->globals, $this->vars), EXTR_SKIP);

        include $this->compile($template);
    }

    /**
     * Compile a template into a cached PHP file and return the compiled path.
     *
     * Compilation occurs when:
     * - The compiled file does not exist yet
     * - The source template is newer than the compiled file
     * - Caching is disabled via configuration
     *
     * The compiler performs a single-pass parse using regex callbacks to transform
     * supported template tags into PHP output/control structures.
     *
     * @param string $template Template filename relative to templateDir
     * @return string Absolute path to the compiled PHP file
     *
     * @throws RuntimeException When the source template does not exist
     */
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
            // Converts supported template directives into PHP:
            // - {$var}                 => echo (tag-stripped with allowlist)
            // - {func(args)}           => echo escaped function output
            // - {if}/{elseif}/{else}   => PHP condition blocks
            // - {foreach}/{for}/{while}=> PHP loop blocks
            // - {block name="..."}     => Comment markers (structure hint only)
            // - {include file="..."}   => Recursive compile + include of partial templates
            // - {raw $var}             => Raw output (use sparingly; trusted content only)
            $allowedFunctions = $this->allowedFunctions;

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
                function($m) use ($allowedFunctions)
                {
                    if (!empty($m[1])) return '<?= strip_tags((string)($' . $m[1] . ' ?? ""), "<b><i><u><strong><em>") ?>';
                    if (!empty($m[2]))
                    {
                        $fn = $m[2];
                        if (!in_array($fn, $allowedFunctions, true))
                        {
                            return '<?= "" ?>';
                        }

                        $args = trim($m[3]);

                        return '<?= (function_exists("' . $fn . '") ? htmlspecialchars(' . $fn . '(' . $args . '), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : "") ?>';
                    }
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
                    if (!empty($m[11]))
                    {
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
            // Normalizes whitespace in compiled output for cleaner diffs and fewer
            // unexpected layout changes in rendered HTML.
            $content = preg_replace('/[ \t]+$/m', '', $content); // trailing spaces
            $content = preg_replace("/\n{3,}/", "\n\n", $content); // max 2 blank lines

            file_put_contents($compiledPath, $content);
        }

        return $compiledPath;
    }

    /**
     * Clear all compiled template files from the cache directory.
     *
     * Useful during development or when deploying template changes.
     * Only removes compiled PHP files generated by this engine.
     */
    public function clearCache(): void
    {
        foreach (glob($this->cacheDir . '/*.php') as $file)
        {
            is_file($file) && unlink($file);
        }
    }
}
