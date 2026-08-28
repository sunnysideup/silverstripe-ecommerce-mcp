<?php

declare(strict_types=1);

namespace Sunnysideup\EcommerceMCP\Registry;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use Sunnysideup\EcommerceMCP\Exceptions\JsonRpcException;
use Sunnysideup\EcommerceMCP\Interfaces\ToolProvider;
use Sunnysideup\EcommerceMCP\Tools\CatalogueTools;

/**
 * Collects tool providers, exposes their definitions for tools/list and
 * routes tools/call to the right handler.
 *
 * Providers are registered by config, not auto-discovered: several providers
 * (e.g. CatalogueTools and its CatalogueToolsPW subclass) share tool names, so
 * scanning every ToolProvider implementor would register duplicates in a
 * non-deterministic order. Explicit registration keeps the surface predictable.
 *
 * Register additional providers via Injector config:
 *
 *   Sunnysideup\EcommerceMCP\Registry\ToolRegistry:
 *     properties:
 *       providers:
 *         orders: '%$Sunnysideup\EcommerceMCP\Tools\OrderTools'
 *
 * To swap the default catalogue provider for a subclass, replace the class at
 * the Injector level rather than touching providers — the default below then
 * resolves to your subclass automatically:
 *
 *   SilverStripe\Core\Injector\Injector:
 *     Sunnysideup\EcommerceMCP\Tools\CatalogueTools:
 *       class: Sunnysideup\EcommerceMCP\Tools\CatalogueToolsPW
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

        // A tool may hand back an in-band error (unknown SKU, query too short)
        // as a normal payload rather than throwing. Surface that as isError so
        // the model treats it as a failure, not data.
        $isError = is_array($payload) && isset($payload['error']);

        // structuredContent is what capable clients reason over. The text block
        // is a compact fallback for clients that ignore structured output —
        // no pretty-printing, since that only spends tokens on whitespace.
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode(
                    $payload,
                    JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                ),
            ]],
            'structuredContent' => $payload,
            'isError' => $isError,
        ];
    }

    /** @return array<string, array{provider: ToolProvider, definition: array}> */
    private function buildIndex(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        // Configured providers win; otherwise fall back to the catalogue. The
        // fallback resolves through Injector, so an Injector class replacement
        // (see the class docblock) transparently swaps in a subclass.
        $providers = $this->providers ?: [
            'catalogue' => Injector::inst()->get(CatalogueTools::class),
        ];

        $index = [];
        foreach ($providers as $provider) {
            // isEnabled() gates the providers actually in use — the previous
            // version checked it only on a discarded auto-discovery set, so
            // is_enabled: false silently did nothing.
            if (!$provider->isEnabled()) {
                continue;
            }

            foreach ($provider->definitions() as $definition) {
                $toolName = $definition['name'];

                // Two providers claiming the same tool name is a config error,
                // not something to resolve silently by shadowing one.
                if (isset($index[$toolName])) {
                    throw new JsonRpcException(
                        -32603,
                        sprintf(
                            'Duplicate tool "%s": %s and %s both provide it',
                            $toolName,
                            get_class($index[$toolName]['provider']),
                            get_class($provider),
                        ),
                        500,
                    );
                }

                $index[$toolName] = [
                    'provider'   => $provider,
                    'definition' => $definition,
                ];
            }
        }

        return $this->index = $index;
    }
}
