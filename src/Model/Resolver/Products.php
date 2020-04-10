<?php
declare(strict_types=1);

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver;

use Exception;
use G4NReact\MsCatalog\Client\ClientFactory;
use G4NReact\MsCatalog\Document;
use G4NReact\MsCatalog\QueryInterface;
use G4NReact\MsCatalogMagento2\Helper\Config as ConfigHelper;
use G4NReact\MsCatalogMagento2\Helper\Facets as FacetsHelper;
use G4NReact\MsCatalogMagento2\Helper\Query;
use G4NReact\MsCatalogMagento2GraphQl\Helper\Parser;
use G4NReact\MsCatalogMagento2GraphQl\Helper\Search as SearchHelper;
use G4NReact\MsCatalogSolr\FieldHelper;
use G4NReact\MsCatalogMagento2GraphQl\Model\AbstractResolver;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Manager as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Search\Model\Query as SearchQuery;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Class Products
 * @package Global4net\CatalogGraphQl\Model\Resolver
 */
class Products extends AbstractResolver
{
    /**
     * CatalogGraphQl products cache key
     */
    const CACHE_KEY_CATEGORY = 'G4N_CAT_GRAPH_QL_PROD';

    /**
     * @var string
     */
    const CACHE_KEY_SEARCH = 'G4N_SEARCH_PROD';

    /**
     * @var String
     */
    const PRODUCT_OBJECT_TYPE = 'product';

    /**
     * @var array
     */
    public static $defaultAttributes = [
        'category',
        'price'
    ];

    /**
     * List of attributes codes that we can skip when returning attributes for product
     *
     * @var array
     */
    public static $attributesToSkip = [];

    /**
     * @var string
     */
    public $resolveInfo;

    /**
     * @var FacetsHelper
     */
    protected $facetsHelper;

    /**
     * @var SearchHelper
     */
    protected $searchHelper;

    /**
     * Products constructor
     *
     * @param CacheInterface $cache
     * @param DeploymentConfig $deploymentConfig
     * @param StoreManagerInterface $storeManager
     * @param Json $serializer
     * @param LoggerInterface $logger
     * @param ConfigHelper $configHelper
     * @param Query $queryHelper
     * @param EventManager $eventManager
     * @param FacetsHelper $facetsHelper
     * @param SearchHelper $searchHelper
     */
    public function __construct(
        CacheInterface $cache,
        DeploymentConfig $deploymentConfig,
        StoreManagerInterface $storeManager,
        Json $serializer,
        LoggerInterface $logger,
        ConfigHelper $configHelper,
        Query $queryHelper,
        EventManager $eventManager,
        FacetsHelper $facetsHelper,
        SearchHelper $searchHelper
    ) {
        $this->facetsHelper = $facetsHelper;
        $this->searchHelper = $searchHelper;

        return parent::__construct(
            $cache,
            $deploymentConfig,
            $storeManager,
            $serializer,
            $logger,
            $configHelper,
            $queryHelper,
            $eventManager
        );
    }

    /**
     * @param Field $field
     * @param ContextInterface $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     * @throws GraphQlInputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws Exception
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        if (isset($context->args)) {
            $argsFromContext = $context->args;
            $args = $args ?: [];
            if ($argsFromContext['overwrite_args'] ?? false) {
                $args = array_merge($args, $context->args);
            } else {
                $args = array_merge($context->args, $args);
            }
        }

        $resolveObject = new DataObject([
            'field'        => $field,
            'context'      => $context,
            'resolve_info' => $info,
            'value'        => $value,
            'args'         => $args
        ]);
        $this->eventManager->dispatch(
            self::PRODUCT_OBJECT_TYPE . '_resolver_resolve_before',
            ['resolve' => $resolveObject]
        );

        $value = $resolveObject->getValue();
        $args = $resolveObject->getArgs();

        if ((isset($args['redirect']) && $args['redirect']) || (isset($args['search']) && $args['search'] == '')) {
            return [
                'items' => [],
                'total_count' => 0,
            ];
        }

        if (!isset($args['search']) && !isset($args['filter'])) {
            throw new GraphQlInputException(
                __("'search' or 'filter' input argument is required.")
            );
        }

        $debug = isset($args['debug']) && $args['debug'];

        $searchEngineConfig = $this->configHelper->getConfiguration();
        $searchEngineClient = ClientFactory::create($searchEngineConfig);

        $query = $searchEngineClient->getQuery();
        $this->handleFilters($query, $args);
        $this->handleSort($query, $args);
        $this->handleFacets($query, $args);

        $this->resolveInfo = $info->getFieldSelection(3);

        // venia outside of variables, he asks for __typename
        $limit = (isset($this->resolveInfo['items']) && isset($this->resolveInfo['items']['__typename'])) ? 2 : 1;
        if ((isset($this->resolveInfo['items']) && count($this->resolveInfo['items']) <= $limit && isset($this->resolveInfo['items']['sku']))
            || (isset($this->resolveInfo['items_ids']))
        ) {
            $maxPageSize = 50000;

            $query->addFieldsToSelect([
                $this->queryHelper->getFieldByAttributeCode('sku'),
            ]);
        } else {
            $this->handleFieldsToSelect($query, $info);
            $maxPageSize = 100; // @todo this should depend on maximum page size in listing
        }

        $pageSize = (isset($args['pageSize']) && ($args['pageSize'] < $maxPageSize)) ? $args['pageSize'] : $maxPageSize;
        $query->setPageSize($pageSize);

        $currentPage = $args['currentPage'] ?? 1;
        $query->setCurrentPage($currentPage);

        if (isset($args['search']) && $args['search']) {
            $this->resolveInfo['total_count'] = true;
            $searchText = Parser::parseSearchText($args['search']);
            $query->setQueryText($searchText);
        }

        $this->eventManager->dispatch(
            'prepare_msproduct_resolver_response_before',
            ['query' => $query, 'resolve_info' => $info, 'args' => $args]
        );
        $response = $query->getResponse();
        $this->eventManager->dispatch(
            'prepare_msproduct_resolver_response_after',
            ['response' => $response]
        );
        $result = $this->prepareResultData($response, $debug);

        if (isset($args['search'])
            && $args['search']
            && isset($result['total_count'])
            && isset($context->magentoSearchQuery)
        ) {
            /** @var SearchQuery $magentoSearchQuery */
            $magentoSearchQuery = $context->magentoSearchQuery;
            if ($magentoSearchQuery && $magentoSearchQuery->getId()) {
                $magentoSearchQuery->setNumResults($result['total_count']);
                $this->searchHelper->updateSearchQueryNumResults($magentoSearchQuery);
            }
        }

        $resultObject = new DataObject(['result' => $result]);
        $this->eventManager->dispatch(
            self::PRODUCT_OBJECT_TYPE . '_resolver_result_return_before',
            ['result' => $resultObject]
        );
        $result = $resultObject->getData('result');

        if(isset($args['filter']['ids']) && isset($result['items'])){
            $order = $args['filter']['ids'];
            usort($result['items'], function ($a, $b) use ($order) {
                $pos_a = array_search($a['id'], $order);
                $pos_b = array_search($b['id'], $order);
                return $pos_a - $pos_b;
            });
        }

        // set args to context for eager loading etc. purposes
        $context->msProductsArgs = $args;
        $context->msProducts = $result;

        return $result;
    }

    /**
     * @param QueryInterface $query
     * @param $args
     * @throws LocalizedException
     */
    public function handleFilters($query, $args)
    {
        $query->addFilters([
            [$this->queryHelper->getFieldByProductAttributeCode(
                'store_id',
                $this->storeManager->getStore()->getId()
            )],
            [$this->queryHelper->getFieldByProductAttributeCode(
                'object_type',
                'product'
            )]
        ]);

        if (!isset($args['filter']['skus'])) {
            $query->addFilter($this->queryHelper->getFieldByProductAttributeCode(
                'visibility',
                $this->prepareFilterValue(['gt' => 1])
            ));
        }

        $this->addOutOfStockFilterProducts($query);

        if (isset($args['filter']) && is_array($args['filter']) && ($filters = $this->prepareFiltersByArgsFilter($args['filter'], 'product'))) {
            $query->addFilters($filters);
        }

        $this->eventManager->dispatch('prepare_msproduct_resolver_filters_add_after', ['query' => $query, 'args' => $args]);
    }

    /**
     * @param QueryInterface $query
     * @throws LocalizedException
     */
    protected function addOutOfStockFilterProducts($query)
    {
        if (!$this->configHelper->getShowOutOfStockProducts()) {
            $query->addFilter(
                $this->queryHelper->getFieldByProductAttributeCode('status', Status::STATUS_ENABLED)
            );
        }
    }

    /**
     * @param QueryInterface $query
     * @param $args
     * @throws LocalizedException
     */
    public function handleSort($query, $args)
    {
        $sortDir = 'ASC';

        if (isset($args['sort']['sort_order']) && in_array($args['sort']['sort_order'], ['ASC', 'DESC'])) {
            $sortDir = $args['sort']['sort_order'];
        }

        $sort = false;
        if (isset($args['sort']) && isset($args['sort']['sort_by'])) {
            $sort = $this->prepareSortField($args['sort']['sort_by'], $args['sort']['sort_order']);
        } elseif (isset($args['search']) && $args['search']) {
            $sort = $this->prepareSortField('score');
            $sortDir = 'DESC';
        }

        $this->eventManager->dispatch('prepare_msproduct_resolver_sort_add_before', ['sort' => $sort, 'sortDir' => $sortDir]);

        if ($sort) {
            $query->addSort($sort);
        }

        $this->eventManager->dispatch('prepare_msproduct_resolver_sort_add_after', ['query' => $query, 'args' => $args]);
    }

    /**
     * @param $sort
     * @param string $sortDir
     * @return Document\Field
     * @throws LocalizedException
     */
    protected function prepareSortField($sort, $sortDir = 'DESC')
    {
        return $this->queryHelper->getFieldByAttributeCode($sort, $sortDir);
    }

    /**
     * @param QueryInterface $query
     * @param $args
     * @throws LocalizedException
     */
    public function handleFacets($query, $args)
    {
        if ($categoryFilter = $query->getFilter('category_id')) {
            /**
             * @todo check if value should be eq here, handle it in another way
             */
            $value = $categoryFilter['field']->getValue();
            if (is_array($value) && isset($value['eq'])) {
                $value = $value['eq'];
            }
            $facetFields = $this->facetsHelper->getFacetFieldsByCategory($value);
            $query->addFacets($facetFields);

            $statsFields = $this->facetsHelper->getStatsFieldsByCategory($value);
            $query->addStats($statsFields);
        }

        if ($baseStats = $this->configHelper->getProductAttributesBaseStats()) {
            foreach ($baseStats as $baseStat) {
                $query->addStat($this->queryHelper->getFieldByAttributeCode($baseStat));
            }
        }

        if ($baseFacets = $this->configHelper->getProductAttributesBaseFacets()) {
            foreach ($baseFacets as $baseFacet) {
                $facetField = $this->queryHelper->getFieldByAttributeCode($baseFacet);
                if ($baseFacet === 'price') {
                    $facetField->setData('limit', 0); // we use stats for price filter
                }
                $query->addFacet($facetField);
            }
        }

        /**
         * @todo only for testing purpopse, eventually handle facets from category,
         */
        $query->addFacet(
            $this->queryHelper->getFieldByAttributeCode('category_id')
        );
    }

    /**
     * @param QueryInterface $query
     * @param $info
     * @throws LocalizedException
     */
    public function handleFieldsToSelect($query, $info)
    {
        $queryFields = $this->parseQueryFields($info);

        $fieldsToSelect = [];
        foreach ($queryFields as $attributeCode => $value) {
            $fieldsToSelect[] = $this->queryHelper->getFieldByAttributeCode($attributeCode);
        }
        $query->addFieldsToSelect($fieldsToSelect);
    }

    /**
     * @param ResolveInfo $info
     * @return array
     */
    public function parseQueryFields(ResolveInfo $info)
    {
        $queryFields = $info->getFieldSelection(1)['items'] ?? [];

        return $queryFields;
    }

    /**
     * @param $response
     * @param $debug
     * @return array
     */
    public function prepareResultData($response, $debug)
    {
        $debugInfo = [];
        if ($debug) {
            $debugQuery = $response->getDebugInfo();
            $debugInfo = $debugQuery['params'] ?? [];
            $debugInfo['code'] = $debugQuery['code'] ?? 0;
            $debugInfo['message'] = $debugQuery['message'] ?? '';
            $debugInfo['uri'] = $debugQuery['uri'] ?? '';
        }

        $products = $this->getProducts($response->getDocumentsCollection());
        $data = [
            'total_count' => $response->getNumFound(),
            'items_ids'   => $products['items_ids'],
            'items'       => $products['items'],
            'page_info'   => [
                'page_size'    => count($response->getDocumentsCollection()),
                'current_page' => $response->getCurrentPage(),
                'total_pages'  => $response->getNumFound()
            ],
            'facets'      => $this->prepareFacets($response->getFacets()),
            'stats'       => $this->prepareStats($response->getStats()),
            'debug_info'  => $debugInfo,
        ];

        return $data;
    }

    /**
     * @param $documentCollection
     * @param string $idType
     * @return array
     */
    public function getProducts($documentCollection)
    {
        $products = [];
        $productIds = [];

        $i = 300; // default for product order in search

        /** @var Document $productDocument */
        foreach ($documentCollection as $productDocument) {
            $this->eventManager->dispatch('prepare_msproduct_resolver_result_before', ['productDocument' => $productDocument]);

            $productData = [];
            foreach ($productDocument->getFields() as $field) {
                $name = $field->getName();
                $value = $field->getValue();

                if ($name == 'sku') {
                    $productIds[] = $value;
                }
                if ($name == 'inventory_sources') {
                    $value = $this->prepareInventorySourcesValue($value);
                }

                $productData[$name] = $value;
            }

            $this->eventManager->dispatch('prepare_msproduct_resolver_result_after', ['productData' => $productData]);
            $products[$i] = $productData;
            $i++;
        }

        ksort($productIds);
        ksort($products);

        return ['items' => $products, 'items_ids' => $productIds];
    }

    /**
     * @param string $value
     * @return array
     */
    protected function prepareInventorySourcesValue(string $value): array
    {
        $preparedValue = json_decode($value, true);

        return ($preparedValue && is_array($preparedValue)) ? $preparedValue : [];
    }

    /**
     * @param array $facets
     * @return array
     */
    public function prepareFacets($facets)
    {
        $preparedFacets = [];

        foreach ($facets as $field => $values) {
            $preparedValues = [];

            foreach ($values as $valueId => $count) {
                if ($valueId) {
                    $preparedValues[] = [
                        'value_id' => $valueId,
                        'count'    => $count
                    ];
                }
            }

            if ($preparedValues) {
                $preparedFacets[] = [
                    'code'   => $field,
                    'values' => $preparedValues
                ];
            }
        }

        return $preparedFacets;
    }

    /**
     * @param $stats
     * @return array
     */
    public function prepareStats($stats)
    {
        $preparedStats = [];
        foreach ($stats as $field => $value) {
            $preparedStats[] = [
                'code'   => FieldHelper::createFieldByResponseField($field, null)->getName(),
                'values' => $value
            ];
        }

        return $preparedStats;
    }

    /**
     * @ToDo: Will we use it?
     *
     * @param array $solrAttributes
     * @return array
     */
    public function parseAttributeCode($solrAttributes = [])
    {
        $newSolrAttributes = [];
        foreach ($solrAttributes as $key => $attribute) {
            $attributeCode = str_replace(['_facet', '_f'], ['', ''], $attribute['code']);
            $newSolrAttributes[$attributeCode]['code'] = $attributeCode;
            $newSolrAttributes[$attributeCode]['values'] = $attribute['values'];
        }

        return $newSolrAttributes;
    }

    /**
     * @ToDo: Will we use it?
     *
     * @param Document $document
     * @return array
     */
    protected function prepareProductAttributes(Document $document): array
    {
        $attributes = [];
        /** @var Document\Field $field */
        foreach ($document->getFields() as $field) {
            if (in_array($field->getName(), self::$attributesToSkip)) {
                continue;
            }

            $attribute = [];

            $name = $field->getName();
            $value = $field->getValue();
            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $attribute['attribute_code'] = $name;
            $attribute['value'] = $value;

            $attributes[] = $attribute;
        }

        return $attributes;
    }
}
