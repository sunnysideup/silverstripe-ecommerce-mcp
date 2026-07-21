<?php

declare(strict_types=1);

namespace Sunnysideup\EcommerceMCP\Registry;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use Sunnysideup\EcommerceMCP\Interfaces\ToolProvider;
use Sunnysideup\EcommerceMCP\Tools\CatalogueTools;
use Sunnysideup\EcommerceMCP\Exceptions\JsonRpcException;

/**
 * Collects tool providers, exposes their definitions for tools/list and
 * routes tools/call to the right handler.
 *
 * Register additional providers via Injector config:
 *
 *   Sunnysideup\EcommerceMCP\ToolRegistry:
 *     properties:
 *       providers:
 *         orders: '%$Sunnysideup\EcommerceMCP\Tools\OrderTools'
 */
class ToolRegistry
{
    use Injectable;

    /** @var array<string, ToolProvider> */
    private array $providers = [];

    /** @var array<string, array{provider: ToolProvider, definition: array}>|null */
    private ?array $index = null;

    public function setProviders(array $providers): static
    {
        $this->providers = $providers;
        $this->index = null;

        return $this;
    }

    /**
     * Tool definitions for tools/list, in the order providers were registered.
     */
    public function definitions(): array
    {
        return array_values(array_map(
            static fn (array $entry): array => $entry['definition'],
            $this->buildIndex(),
        ));
    }

    /**
     * Execute a tool and wrap its return value in a CallToolResult.
     */
    public function call(string $name, array $arguments): array
    {
        $index = $this->buildIndex();

        if (!isset($index[$name])) {
            throw new JsonRpcException(-32602, sprintf('Unknown tool "%s"', $name), 400);
        }

        $payload = $index[$name]['provider']->execute($name, $arguments);

        // structuredContent is what the model actually reasons over; the text
        // block is a fallback for clients that ignore structured output.
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ]],
            'structuredContent' => $payload,
            'isError' => false,
        ];
    }

    /** @return array<string, array{provider: ToolProvider, definition: array}> */
    private function buildIndex(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $providers = $this->providers ?: [
            'catalogue' => Injector::inst()->get(CatalogueTools::class),
        ];

        $index = [];
        foreach ($providers as $provider) {
            foreach ($provider->definitions() as $definition) {
                $index[$definition['name']] = [
                    'provider'   => $provider,
                    'definition' => $definition,
                ];
            }
        }

        return $this->index = $index;
    }
}
