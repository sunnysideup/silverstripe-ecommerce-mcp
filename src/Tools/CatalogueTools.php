<?php

declare(strict_types=1);

namespace Sunnysideup\EcommerceMCP\Tools;

use Sunnysideup\EcommerceMCP\Exceptions\JsonRpcException;
use Sunnysideup\EcommerceMCP\Interfaces\ToolProvider;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;

/**
 * The catalogue surface exposed to agents.
 *
 * Design rule: every tool returns the smallest useful payload. An agent has a
 * finite context window, and a fat response is worse than no response — it
 * crowds out the reasoning that would have used it. search_products returns
 * summaries only; the agent calls get_product when it needs depth.
 */
class CatalogueTools implements ToolProvider
{
    use Injectable;
    use Configurable;

    private static int $max_results = 20;

    private static int $category_ttl_ms = 3600000;

    private static string $product_class = 'App\Model\Product';

    public function definitions(): array
    {
        return [
            [
                'name'        => 'search_products',
                'title'       => 'Search products',
                'description' => 'Search the product catalogue by keyword, with optional '
                    . 'category and price filters. Returns brief summaries. Call get_product '
                    . 'for full detail on a specific SKU.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => [
                            'type'        => 'string',
                            'description' => 'Keywords to match against product title and description.',
                            'minLength'   => 2,
                        ],
                        'category' => [
                            'type'        => 'string',
                            'description' => 'Restrict to a category. Use the url_segment '
                                . 'returned by list_categories.',
                        ],
                        'min_price' => ['type' => 'number', 'minimum' => 0],
                        'max_price' => ['type' => 'number', 'minimum' => 0],
                        'in_stock_only' => [
                            'type'        => 'boolean',
                            'description' => 'Exclude products with no available stock.',
                            'default'     => false,
                        ],
                        'limit' => [
                            'type'        => 'integer',
                            'description' => 'Maximum results to return.',
                            'minimum'     => 1,
                            'maximum'     => 20,
                            'default'     => 10,
                        ],
                    ],
                    'required'             => ['query'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'get_product',
                'title'       => 'Get product detail',
                'description' => 'Full detail for one product: description, specifications, '
                    . 'variants, current price and stock.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'sku' => ['type' => 'string', 'description' => 'Product SKU.'],
                    ],
                    'required'             => ['sku'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'list_categories',
                'title'       => 'List categories',
                'description' => 'The category tree. Use this first to learn the vocabulary '
                    . 'this catalogue uses before searching.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'properties'           => new \stdClass(),
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'check_stock',
                'title'       => 'Check stock',
                'description' => 'Live availability for up to 20 SKUs. Always call this before '
                    . 'telling a customer something is in stock.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'skus' => [
                            'type'     => 'array',
                            'items'    => ['type' => 'string'],
                            'minItems' => 1,
                            'maxItems' => 20,
                        ],
                    ],
                    'required'             => ['skus'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public function execute(string $name, array $arguments): mixed
    {
        return match ($name) {
            'search_products' => $this->searchProducts($arguments),
            'get_product'     => $this->getProduct($arguments),
            'list_categories' => $this->listCategories(),
            'check_stock'     => $this->checkStock($arguments),
            default => throw new JsonRpcException(-32602, sprintf('Unknown tool "%s"', $name), 400),
        };
    }

    // ------------------------------------------------------------------ tools

    private function searchProducts(array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        if (strlen($query) < 2) {
            throw new JsonRpcException(-32602, 'query must be at least 2 characters', 400);
        }

        $ceiling = (int) static::config()->get('max_results');
        $limit   = min((int) ($args['limit'] ?? 10), $ceiling);

        // NOTE: PartialMatch is a LIKE '%...%' scan and will not survive a
        // catalogue of any size. Swap this for your search index — see
        // ProductSearchIndex in the README.
        $list = $this->products()
            ->filterAny([
                'Title:PartialMatch'   => $query,
                'Content:PartialMatch' => $query,
            ]);

        if (!empty($args['category'])) {
            $list = $list->filter('Category.URLSegment', (string) $args['category']);
        }
        if (isset($args['min_price'])) {
            $list = $list->filter('Price:GreaterThanOrEqual', (float) $args['min_price']);
        }
        if (isset($args['max_price'])) {
            $list = $list->filter('Price:LessThanOrEqual', (float) $args['max_price']);
        }
        if (!empty($args['in_stock_only'])) {
            $list = $list->filter('StockLevel:GreaterThan', 0);
        }

        $matched = $list->count();
        $results = [];
        foreach ($list->limit($limit) as $product) {
            $results[] = $this->summarise($product);
        }

        return [
            'results'       => $results,
            'returned'      => count($results),
            'total_matched' => $matched,
            'truncated'     => $matched > count($results),
            'hint'          => $matched > count($results)
                ? 'More matches exist. Narrow the query or add filters rather than raising limit.'
                : null,
        ];
    }

    private function getProduct(array $args): array
    {
        $product = $this->findBySku((string) ($args['sku'] ?? ''));

        return $this->summarise($product) + [
            'description'  => $this->plainText($product->Content ?? ''),
            'brand'        => $product->Brand ?? null,
            'category'     => $product->Category()->Title ?? null,
            'specifications' => $this->specifications($product),
            'variants'     => $this->variants($product),
            'image'        => $product->hasMethod('Image') && $product->Image()->exists()
                ? $product->Image()->getAbsoluteURL()
                : null,
        ];
    }

    private function listCategories(): array
    {
        $categories = [];

        foreach ($this->categoryClass()::get()->sort('Title') as $category) {
            $categories[] = [
                'title'         => $category->Title,
                'url_segment'   => $category->URLSegment,
                'product_count' => $category->Products()->count(),
            ];
        }

        return ['categories' => $categories, 'ttlMs' => static::config()->get('category_ttl_ms')];
    }

    private function checkStock(array $args): array
    {
        $skus = array_slice((array) ($args['skus'] ?? []), 0, 20);
        if ($skus === []) {
            throw new JsonRpcException(-32602, 'skus must contain at least one SKU', 400);
        }

        $stock = [];
        foreach ($this->products()->filter('SKU', $skus) as $product) {
            $stock[$product->SKU] = [
                'sku'         => $product->SKU,
                'available'   => (int) $product->StockLevel,
                'status'      => $this->stockStatus($product),
                'price'       => $this->price($product),
            ];
        }

        // Report SKUs we could not resolve rather than silently dropping them —
        // an agent that gets four results for five SKUs will otherwise guess.
        $unknown = array_values(array_diff($skus, array_keys($stock)));

        return ['stock' => array_values($stock), 'unknown_skus' => $unknown];
    }

    // ----------------------------------------------------------------- shaping

    private function summarise(DataObject $product): array
    {
        return [
            'sku'    => $product->SKU,
            'title'  => $product->Title,
            'price'  => $this->price($product),
            'status' => $this->stockStatus($product),
            'url'    => $product->AbsoluteLink(),
        ];
    }

    private function price(DataObject $product): array
    {
        return [
            'amount'   => round((float) $product->Price, 2),
            'currency' => 'NZD',
        ];
    }

    private function stockStatus(DataObject $product): string
    {
        return ((int) $product->StockLevel) > 0 ? 'in_stock' : 'out_of_stock';
    }

    private function specifications(DataObject $product): array
    {
        if (!$product->hasMethod('Specifications')) {
            return [];
        }

        $specs = [];
        foreach ($product->Specifications() as $spec) {
            $specs[$spec->Label] = $spec->Value;
        }

        return $specs;
    }

    private function variants(DataObject $product): array
    {
        if (!$product->hasMethod('Variants')) {
            return [];
        }

        $variants = [];
        foreach ($product->Variants() as $variant) {
            $variants[] = [
                'sku'    => $variant->SKU,
                'title'  => $variant->Title,
                'price'  => $this->price($variant),
                'status' => $this->stockStatus($variant),
            ];
        }

        return $variants;
    }

    /**
     * Agents pay for every token. Strip markup and cap length rather than
     * shipping a 4,000-word CMS description.
     */
    private function plainText(string $html, int $maxChars = 1200): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');

        return strlen($text) > $maxChars
            ? rtrim(substr($text, 0, $maxChars)) . '…'
            : $text;
    }

    // ------------------------------------------------------------------ lookup

    private function findBySku(string $sku): DataObject
    {
        $product = $this->products()->filter('SKU', $sku)->first();

        if (!$product) {
            throw new JsonRpcException(-32602, sprintf('No product with SKU "%s"', $sku), 400);
        }

        return $product;
    }

    /**
     * Base list. Everything an agent can see must be publicly visible —
     * this is the single choke point for that guarantee.
     */
    private function products(): DataList
    {
        $class = (string) static::config()->get('product_class');

        return $class::get()->filter(['ShowInSearch' => 1]);
    }

    private function categoryClass(): string
    {
        return 'App\Model\ProductCategory';
    }
}
