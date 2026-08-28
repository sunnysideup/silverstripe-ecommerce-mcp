<?php

declare(strict_types=1);

namespace Sunnysideup\EcommerceMCP\Tasks;

use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use Sunnysideup\EcommerceMCP\Exceptions\JsonRpcException;
use Sunnysideup\EcommerceMCP\Registry\ToolRegistry;

/**
 * Interactive test form for shop owners to test MCP catalogue tools.
 *
 * Run via browser:  /dev/tasks/mcp-test
 * Run via CLI:      sake dev/tasks/mcp-test   (runs smoke tests)
 *
 * Extension seams: definitions(), callTool() and smokeTests() are the three
 * points a subclass overrides to drive a specific ToolProvider instead of the
 * globally-registered ToolRegistry — see McpTestTaskPW.
 */
class McpTestTask extends BuildTask
{
    private static $segment = 'mcp-test';

    protected $title = 'MCP Catalogue Test';

    protected $description = 'Interactive form to test MCP catalogue tools with custom parameters.';

    public function run($request): void
    {
        $definitions = $this->definitions();

        $tool      = (string) ($request->getVar('tool') ?? '');
        $submitted = $request->getVar('submit') !== null;

        if (Director::is_cli()) {
            $this->runCliMode($definitions);
            return;
        }

        echo $this->renderStyles();
        echo '<div class="mcp-test">';
        echo '<h1>MCP Catalogue Tools Test</h1>';
        echo '<p>Select a tool and fill in the parameters to test the MCP endpoint.</p>';

        // Tool selector
        echo '<form method="get" class="tool-selector">';
        echo '<label for="tool"><strong>Select Tool:</strong></label> ';
        echo '<select name="tool" id="tool" onchange="this.form.submit()">';
        echo '<option value="">-- Choose a tool --</option>';
        foreach ($definitions as $def) {
            $selected = $tool === $def['name'] ? ' selected' : '';
            echo sprintf(
                '<option value="%s"%s>%s</option>',
                htmlspecialchars($def['name']),
                $selected,
                htmlspecialchars($def['title'] ?? $def['name'])
            );
        }
        echo '</select>';
        echo '</form>';

        // Selected tool's parameter form
        if ($tool !== '') {
            $toolDef = $this->findToolDefinition($definitions, $tool);
            if ($toolDef) {
                echo $this->renderToolForm($toolDef, $request);

                if ($submitted) {
                    $arguments = $this->extractArguments($toolDef, $request);
                    $this->executeAndRender($tool, $arguments);
                }
            }
        }

        // Available tools summary
        echo '<div class="tools-summary">';
        echo '<h2>Available Tools</h2>';
        foreach ($definitions as $def) {
            echo sprintf(
                '<div class="tool-info"><strong>%s</strong>: %s</div>',
                htmlspecialchars($def['name']),
                htmlspecialchars($def['description'])
            );
        }
        echo '</div>';

        echo '</div>';
    }

    // -------------------------------------------------- extension seams

    /**
     * The tool definitions to render. Override to test a specific provider.
     */
    protected function definitions(): array
    {
        return $this->registry()->definitions();
    }

    /**
     * Execute a tool and return its own payload (envelope unwrapped). Override
     * to route calls at a specific provider rather than the shared registry.
     * May throw JsonRpcException; callers handle it.
     */
    protected function callTool(string $tool, array $arguments): array
    {
        $result = $this->registry()->call($tool, $arguments);

        // The registry wraps tool output in an MCP envelope; the renderer wants
        // the tool's own structured payload.
        return is_array($result)
            ? ($result['structuredContent'] ?? $result)
            : ['result' => $result];
    }

    /**
     * CLI smoke tests, as [label, tool, args, summary] rows. Subclasses
     * array_merge their own onto these.
     */
    protected function smokeTests(): array
    {
        return [
            [
                'label'   => 'list_categories',
                'tool'    => 'list_categories',
                'args'    => [],
                'summary' => fn (array $r): string => sprintf('Found %d categories', count($r['categories'] ?? [])),
            ],
            [
                'label'   => "search_products (query: 'test')",
                'tool'    => 'search_products',
                'args'    => ['query' => 'test', 'limit' => 3],
                'summary' => fn (array $r): string => sprintf(
                    'Returned: %d, Total: %d',
                    $r['returned'] ?? 0,
                    $r['total_matched'] ?? 0
                ),
            ],
        ];
    }

    private function registry(): ToolRegistry
    {
        return Injector::inst()->get(ToolRegistry::class);
    }

    // -------------------------------------------------- form helpers

    private function findToolDefinition(array $definitions, string $name): ?array
    {
        foreach ($definitions as $def) {
            if ($def['name'] === $name) {
                return $def;
            }
        }
        return null;
    }

    private function renderToolForm(array $toolDef, $request): string
    {
        $html = '<div class="tool-form">';
        $html .= sprintf('<h2>%s</h2>', htmlspecialchars($toolDef['title'] ?? $toolDef['name']));
        $html .= sprintf('<p class="description">%s</p>', htmlspecialchars($toolDef['description']));

        $html .= '<form method="get">';
        $html .= sprintf('<input type="hidden" name="tool" value="%s">', htmlspecialchars($toolDef['name']));

        $schema     = $toolDef['inputSchema'] ?? [];
        $properties = $schema['properties'] ?? [];
        $required   = $schema['required'] ?? [];

        if (empty($properties) || $properties instanceof \stdClass) {
            $html .= '<p><em>This tool has no parameters.</em></p>';
        } else {
            $html .= '<table class="params">';
            foreach ($properties as $name => $prop) {
                $isRequired = in_array($name, $required, true);
                $type       = $prop['type'] ?? 'string';
                $value      = $request->getVar($name) ?? ($prop['default'] ?? '');
                $desc       = $prop['description'] ?? '';

                $html .= '<tr>';
                $html .= sprintf(
                    '<td class="param-name"><label for="%s">%s%s</label></td>',
                    htmlspecialchars($name),
                    htmlspecialchars($name),
                    $isRequired ? ' <span class="required">*</span>' : ''
                );
                $html .= '<td class="param-input">';
                $html .= $this->renderInput($name, $type, $value, $prop);
                $html .= '</td>';
                $html .= sprintf('<td class="param-desc">%s</td>', htmlspecialchars($desc));
                $html .= '</tr>';
            }
            $html .= '</table>';
        }

        $html .= '<button type="submit" name="submit" value="1">Execute Tool</button>';
        $html .= '</form>';
        $html .= '</div>';

        return $html;
    }

    private function renderInput(string $name, string $type, mixed $value, array $prop): string
    {
        $htmlName = htmlspecialchars($name);

        switch ($type) {
            case 'boolean':
                $checked = $value ? ' checked' : '';
                return sprintf(
                    '<input type="checkbox" name="%s" id="%s" value="1"%s>',
                    $htmlName,
                    $htmlName,
                    $checked
                );

            case 'integer':
            case 'number':
                $min = isset($prop['minimum']) ? ' min="' . (int) $prop['minimum'] . '"' : '';
                $max = isset($prop['maximum']) ? ' max="' . (int) $prop['maximum'] . '"' : '';
                return sprintf(
                    '<input type="number" name="%s" id="%s" value="%s"%s%s step="%s">',
                    $htmlName,
                    $htmlName,
                    htmlspecialchars((string) $value),
                    $min,
                    $max,
                    $type === 'integer' ? '1' : 'any'
                );

            case 'array':
                // One item per line; extractArguments() splits and casts.
                $itemType   = $prop['items']['type'] ?? 'string';
                $placeholder = $itemType === 'integer' || $itemType === 'number'
                    ? 'One number per line'
                    : 'One item per line';
                $textValue = is_array($value) ? implode("\n", $value) : $value;
                return sprintf(
                    '<textarea name="%s" id="%s" rows="3" placeholder="%s">%s</textarea>',
                    $htmlName,
                    $htmlName,
                    $placeholder,
                    htmlspecialchars((string) $textValue)
                );

            default: // string
                $minLen = $prop['minLength'] ?? 0;
                return sprintf(
                    '<input type="text" name="%s" id="%s" value="%s"%s>',
                    $htmlName,
                    $htmlName,
                    htmlspecialchars((string) $value),
                    $minLen ? ' minlength="' . (int) $minLen . '"' : ''
                );
        }
    }

    private function extractArguments(array $toolDef, $request): array
    {
        $schema     = $toolDef['inputSchema'] ?? [];
        $properties = $schema['properties'] ?? [];
        $arguments  = [];

        if (empty($properties) || $properties instanceof \stdClass) {
            return [];
        }

        foreach ($properties as $name => $prop) {
            $value = $request->getVar($name);
            $type  = $prop['type'] ?? 'string';

            if ($value === null || $value === '') {
                continue;
            }

            switch ($type) {
                case 'boolean':
                    $arguments[$name] = (bool) $value;
                    break;
                case 'integer':
                    $arguments[$name] = (int) $value;
                    break;
                case 'number':
                    $arguments[$name] = (float) $value;
                    break;
                case 'array':
                    $items = array_filter(
                        array_map('trim', explode("\n", (string) $value)),
                        static fn ($v): bool => $v !== ''
                    );
                    // Cast to the declared item type so IDs arrive as ints.
                    $itemType = $prop['items']['type'] ?? 'string';
                    if ($itemType === 'integer') {
                        $items = array_map('intval', $items);
                    } elseif ($itemType === 'number') {
                        $items = array_map('floatval', $items);
                    }
                    if ($items !== []) {
                        $arguments[$name] = array_values($items);
                    }
                    break;
                default:
                    $arguments[$name] = (string) $value;
            }
        }

        return $arguments;
    }

    private function executeAndRender(string $tool, array $arguments): void
    {
        echo '<div class="result">';
        echo '<h2>Result</h2>';

        echo '<div class="request">';
        echo '<h3>Request</h3>';
        echo '<pre>' . htmlspecialchars(json_encode(
            ['tool' => $tool, 'arguments' => $arguments],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        )) . '</pre>';
        echo '</div>';

        echo '<div class="response">';
        echo '<h3>Response</h3>';
        try {
            $result  = $this->callTool($tool, $arguments);
            // A tool-level error is a normal payload with an 'error' key, not
            // an exception — style it red rather than pretending it succeeded.
            $isError = isset($result['error']);
            echo '<pre class="' . ($isError ? 'error' : 'success') . '">'
                . htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                . '</pre>';
        } catch (JsonRpcException $e) {
            echo '<pre class="error">' . htmlspecialchars(json_encode([
                'error' => [
                    'code'    => $e->getCode(),
                    'message' => $e->getMessage(),
                    'data'    => $e->getData(),
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
        } catch (\Throwable $e) {
            echo '<pre class="error">' . htmlspecialchars(json_encode([
                'error' => ['code' => -32603, 'message' => $e->getMessage()],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
        }
        echo '</div>';
        echo '</div>';
    }

    // -------------------------------------------------- CLI

    protected function runCliMode(array $definitions): void
    {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "MCP Catalogue Tools Test (CLI Mode)\n";
        echo str_repeat('=', 60) . "\n\n";

        echo "Available tools:\n";
        foreach ($definitions as $def) {
            echo sprintf("  - %s: %s\n", $def['name'], $def['title'] ?? $def['description']);
        }

        echo "\nRunning basic tests...\n\n";

        foreach ($this->smokeTests() as $test) {
            echo sprintf("--- %s ---\n", $test['label']);
            try {
                $result = $this->callTool($test['tool'], $test['args']);
                if (isset($result['error'])) {
                    $message = is_string($result['error']) ? $result['error'] : json_encode($result['error']);
                    echo '[ERROR] ' . $message . "\n\n";
                    continue;
                }
                if (isset($test['summary'])) {
                    echo $test['summary']($result) . "\n";
                }
                echo "[OK]\n\n";
            } catch (\Throwable $e) {
                echo '[ERROR] ' . $e->getMessage() . "\n\n";
            }
        }

        $segment = Config::inst()->get(static::class, 'segment');
        echo "For interactive testing, use the browser interface at /dev/tasks/{$segment}\n";
    }

    private function renderStyles(): string
    {
        return <<<'HTML'
<style>
.mcp-test {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    max-width: 1000px;
    margin: 20px auto;
    padding: 20px;
}
.mcp-test h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
.mcp-test h2 { color: #555; margin-top: 30px; }
.tool-selector { margin: 20px 0; padding: 15px; background: #f5f5f5; border-radius: 5px; }
.tool-selector select { padding: 8px 12px; font-size: 14px; min-width: 250px; }
.tool-form { margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ddd; border-radius: 5px; }
.tool-form .description { color: #666; font-style: italic; }
.params { width: 100%; border-collapse: collapse; margin: 15px 0; }
.params td { padding: 10px; border-bottom: 1px solid #eee; vertical-align: top; }
.param-name { font-weight: bold; width: 150px; }
.param-name .required { color: #dc3545; }
.param-input input[type="text"],
.param-input input[type="number"],
.param-input textarea { width: 250px; padding: 8px; border: 1px solid #ccc; border-radius: 3px; }
.param-input textarea { vertical-align: top; }
.param-desc { color: #666; font-size: 13px; }
button[type="submit"] {
    background: #007bff; color: #fff; border: none; padding: 12px 24px;
    font-size: 14px; border-radius: 5px; cursor: pointer; margin-top: 10px;
}
button[type="submit"]:hover { background: #0056b3; }
.result { margin: 30px 0; }
.result h3 { color: #333; margin-bottom: 10px; }
.result pre {
    background: #f8f9fa; padding: 15px; border-radius: 5px;
    overflow-x: auto; font-size: 13px; line-height: 1.5;
}
.result pre.success { border-left: 4px solid #28a745; }
.result pre.error { border-left: 4px solid #dc3545; background: #fff5f5; }
.tools-summary { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; }
.tool-info { padding: 8px 0; border-bottom: 1px solid #eee; }
.tool-info strong { color: #007bff; }
</style>
HTML;
    }
}
