<?php

declare(strict_types=1);

namespace Sunnysideup\EcommerceMCP\Tools;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use Sunnysideup\Ecommerce\Api\ArrayMethods;
use Sunnysideup\Ecommerce\Api\KeywordSearchBuilder;
use Sunnysideup\Ecommerce\Model\Money\EcommerceCurrency;
use Sunnysideup\Ecommerce\Pages\Product;
use Sunnysideup\Ecommerce\Pages\ProductGroup;
use Sunnysideup\Ecommerce\ProductsAndGroups\Applyers\ProductSearchFilter;
use Sunnysideup\EcommerceMCP\Exceptions\JsonRpcException;
use Sunnysideup\EcommerceMCP\Interfaces\ToolProvider;

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
    /**
     * The parent's filter seam sees only category_ids. We stash the full search
     * args here in searchProducts() so filterListByCategory() can read brand_ids
     * and in_stock back out. Reset on every search; never read outside that flow.
     */
    protected array $searchArgs = [];

    private static bool $is_enabled = true;

    public function isEnabled(): bool
    {
        return $this->config()->get('is_enabled');
    }

    private static int $max_results = 20;

    /**
     * how long before the categories should be rechecked?
     */
    private static int $category_ttl_ms = 3600000;

    private static string $product_class = Product::class;
    private static string $category_class = ProductGroup::class;

    private static array $product_filter = [
        'ShowInSearch' => 1,
        'AllowPurchase' => 1,
    ];

    private static array $category_filter = [
        'ShowInSearch' => 1,
    ];

    public function definitions(): array
    {
        return [
            [
                'name'        => 'search_products',
                'title'       => 'Search products',
                'description' => 'Search the product catalogue. Use broad keywords first (e.g., "shoes" not "red nike running shoes"). If total_matched is high, use filters or add keywords. Always use this to find SKUs before calling get_product.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => [
                            'type'        => 'string',
                            'description' => 'Keywords to match against product title and description.',
                        ],
                        'category_ids' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'integer'],
                            'description' => 'Restrict to one or more category IDs returned by list_categories. Matches products in ANY of the provided categories.',
                        ],
                        'min_price' => ['type' => 'number', 'minimum' => 0],
                        'max_price' => ['type' => 'number', 'minimum' => 0],
                        'limit' => [
                            'type'        => 'integer',
                            'description' => 'Maximum results to return.',
                            'minimum'     => 1,
                            'maximum'     => 20,
                            'default'     => 10,
                        ],
                        'offset' => [
                            'type'        => 'integer',
                            'description' => 'Pagination offset. Use to fetch the next page of results if total_matched > returned.',
                            'minimum'     => 0,
                            'default'     => 0,
                        ],
                    ],
                    'required'             => [],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'get_product',
                'title'       => 'Get product detail',
                'description' => 'Retrieve deep details, exact stock availability, and variations for a specific product using its SKU.',
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
                'description' => 'The category tree. Use this first to learn the exact vocabulary and IDs this store uses before searching.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'properties'           => new \stdClass(),
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public function execute(string $name, array $arguments): mixed
    {
        $this->searchArgs = $arguments;
        return match ($name) {
            'search_products' => $this->searchProducts(),
            'get_product'     => $this->getProduct(),
            'list_categories' => $this->listCategories(),
            default => throw new JsonRpcException(-32602, sprintf('Unknown tool "%s"', $name), 400),
        };
    }

    // ------------------------------------------------------------------ tools
    protected function hasSearchConstraint(): bool
    {
        return trim((string)($this->searchArgs['query'] ?? '')) !== ''
            || !empty($this->searchArgs['category_ids'])
            || isset($this->searchArgs['min_price'])
            || isset($this->searchArgs['max_price']);
    }

    protected function searchProducts(): array
    {
        if (! $this->hasSearchConstraint()) {
            return [
                'results'       => [],
                'returned'      => 0,
                'total_matched' => 0,
                'truncated'     => false,
                'hint'          => 'Provide at least one query parameter.',
            ];
        }
        $query = Convert::raw2sql(trim((string) ($this->searchArgs['query'] ?? '')));

        if (strlen($query) < 2) {
            $query = '';
        }

        $ceiling = (int) $this->config()->get('max_results');
        $limit   = min((int) ($this->searchArgs['limit'] ?? 10), $ceiling);
        $offset  = max(0, (int) ($this->searchArgs['offset'] ?? 0));

        $list = $this->products();
        $productIds = [];
        if ($query) {
            /** @var Injectable|KeywordSearchBuilder $searchApi */
            $searchApi = KeywordSearchBuilder::create();

            $phrase = ProductSearchFilter::keyword_sanitised($query);
            $phrase = $searchApi->processKeyword($phrase);

            $productIds = $searchApi->getProductResults($phrase, '', $ceiling * 10);

            if (empty($productIds)) {
                // Log zero-result searches to help build better search synonyms later
                Injector::inst()->get(LoggerInterface::class)->info('MCP Zero Results Search: ' . $query);
                return [
                    'results'       => [],
                    'returned'      => 0,
                    'total_matched' => 0,
                    'truncated'     => false,
                    'hint'          => 'Try broader keywords or checking list_categories for correct terminology.',
                ];
            }
            $list = $list->filter('ID', $productIds);
        }


        // Apply filters
        $list = $this->filterListByCategory($list);

        if (isset($this->searchArgs['min_price'])) {
            $list = $list->filter('Price:GreaterThanOrEqual', (float) $this->searchArgs['min_price']);
        }
        if (isset($this->searchArgs['max_price'])) {
            $list = $list->filter('Price:LessThanOrEqual', (float) $this->searchArgs['max_price']);
        }

        // other searches?
        $list = $this->searchProductsExtended($list);

        // count results!
        $matched = $list->count();

        // figure out sort
        if ($productIds !== []) {
            $productIds = array_values(array_intersect($productIds, $list->columnUnique()));
        } else {
            $productIds = $list->columnUnique();
        }
        $sorter = ArrayMethods::create_sort_statement_from_id_array($productIds, $this->getProductClass());
        $list = $list->orderBy($sorter);
        $list = $this->searchProductsExtendedSort($list);

        // Apply pagination (limit and offset)
        $results = [];
        foreach ($list->limit($limit, $offset) as $product) {
            $results[] = $this->summarise($product);
        }

        $totalReturned = $offset + count($results);

        return [
            'results'       => $results,
            'returned'      => count($results),
            'offset'        => $offset,
            'total_matched' => $matched,
            'truncated'     => $matched > $totalReturned,
            'hint'          => $matched > $totalReturned
                ? sprintf('Displaying %d-%d of %d. Pass offset=%d to see the next page.', $offset + 1, $totalReturned, $matched, $totalReturned)
                : null,
        ];
    }

    protected function getProduct(): array
    {
        $sku = (string) ($this->searchArgs['sku'] ?? '');
        $product = $this->findBySku($sku);

        if (! $product) {
            return [
                'sku'   => $sku,
                'error' => sprintf('No product found with SKU "%s".', $sku),
            ];
        }

        return $this->summarise($product) + [
            'description'  => $this->plainText($product->Content ?? ''),
            'category'     => $product->Parent()->exists() ? $product->Parent()->Title : null,
            'image'        => $product->hasMethod('Image') && $product->Image()->exists()
                ? $product->Image()->getAbsoluteURL()
                : null,
            // Expose basic availability status
            'can_purchase' => $product->hasMethod('canPurchase') ? $product->canPurchase() : true,
        ];
    }

    protected function listCategories(): array
    {
        $categoriesClass = (string) $this->getCategoryClass();
        $filter          = (array) $this->getCategoryFilter();

        // One query; iterate the array, not the DataList, to avoid re-querying.
        $list = $categoriesClass::get()->filter($filter)->sort('Sort');
        $list = $this->listCategoriesExtended($list);
        $all = $list->toArray();

        $byParent = [];
        $ids      = [];
        foreach ($all as $cat) {
            $byParent[(int) $cat->ParentID][] = $cat;
            $ids[(int) $cat->ID] = true;
        }

        $build = function (array $nodes) use (&$build, $byParent): array {
            $out = [];
            foreach ($nodes as $cat) {
                $entry = ['id' => (int) $cat->ID, 'title' => $cat->Title];
                $children = $byParent[(int) $cat->ID] ?? [];
                if ($children !== []) {
                    $entry['children'] = $build($children);
                }
                $out[] = $entry;
            }
            return $out;
        };

        // A node is a root when its parent isn't itself a visible category here —
        // handles the shop-root ProductGroup being shown or hidden, either way.
        $roots = array_filter($all, static fn ($cat): bool => !isset($ids[(int) $cat->ParentID]));

        return [
            'categories' => $build(array_values($roots)),
            'ttlMs'      => $this->config()->get('category_ttl_ms'),
        ];
    }

    // ----------------------------------------------------------------- shaping

    protected function summarise(DataObject $product): array
    {
        return [
            'sku'        => $product->InternalItemID,
            'title'      => $product->Title,
            'type'       => $product->i18n_singular_name(),
            'breadcrumb' => $product->ProductBreadcrumb ?? null,
            'price'      => $this->price($product),
            'url'        => $product->AbsoluteLink(),
        ];
    }

    protected function price(DataObject $product): array
    {
        return [
            'amount'   => round((float) $product->Price, 2),
            'currency' => EcommerceCurrency::default_currency_code(),
        ];
    }

    // ------------------------------------------------------------------ lookup

    /**
     * Finds a product by its SKU.
     */
    protected function findBySku(string $sku): ?DataObject
    {
        if (!$sku) {
            return null;
        }

        return Product::get()->filter('InternalItemID', Convert::raw2sql($sku))->first();
    }

    protected function products(): DataList
    {
        $class = (string) $this->getProductClass();
        $filter = (array) $this->getProductFilter();
        return $class::get()->filter($filter);
    }

    /**
     * Converts CMS HTML into agent-friendly pseudo-markdown.
     * Preserves list structure and paragraph breaks before stripping tags.
     */
    protected function plainText(string $html, int $maxChars = 1200): string
    {
        // Convert common block elements to text-based equivalents
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html ?? '');
        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = preg_replace('/<li[^>]*>/i', "\n* ", $html);

        // Strip the rest of the HTML tags
        $text = trim(strip_tags($html));

        // Collapse excess whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return strlen($text) > $maxChars
            ? rtrim(substr($text, 0, $maxChars)) . '…'
            : $text;
    }

    /**
     * Filters a DataList of products by category IDs.
     */
    protected function filterListByCategory(DataList $list): DataList
    {
        $categoryIds = (array) ($this->searchArgs['category_ids'] ?? []);
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        if (empty($categoryIds)) {
            return $list;
        }

        $categoriesClass = (string) $this->config()->get('category_class');
        $allowedProductIds = [];

        foreach ($categoryIds as $categoryId) {
            $category = $categoriesClass::get()->byID((int) $categoryId);
            if ($category) {
                $products = $category->getProducts();
                if ($products && $products->exists()) {
                    $allowedProductIds = array_merge($allowedProductIds, $products->column('ID'));
                }
            }
        }

        return $list->filter('ID', array_unique($allowedProductIds));
    }

    protected function searchProductsExtended(DataList $list): DataList
    {
        // Implement any additional filtering logic here.
        return $list;
    }

    protected function searchProductsExtendedSort(DataList $list): DataList
    {
        // Implement any additional sorting logic here.
        return $list;
    }
    protected function listCategoriesExtended(DataList $list): DataList
    {
        // Implement any additional listing logic here.
        return $list;
    }

    protected function getProductClass(): string
    {
        return (string) $this->config()->get('product_class');
    }

    protected function getProductFilter(): array
    {
        return (array) $this->config()->get('product_filter');
    }

    protected function getCategoryClass(): string
    {
        return (string) $this->config()->get('category_class');
    }
    protected function getCategoryFilter(): array
    {
        return (array) $this->config()->get('category_filter');
    }



}
