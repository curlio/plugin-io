<?php
/**
 * Created by IntelliJ IDEA.
 * User: ihussein
 * Date: 01.08.17
 * Time: 14:58
 */

namespace IO\Api\Resources;

use IO\Services\ItemWishListService;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Http\Request;
use IO\Api\ApiResource;
use IO\Api\ApiResponse;
use IO\Api\ResponseCode;
use Plenty\Modules\Plugin\Storage\Contracts\StorageRepositoryContract;

/**
 * Class ResetTemplateCacheResource
 * @package IO\Api\Resources
 */
class ResetTemplateCacheResource extends ApiResource
{
    /**
     * @var StorageRepositoryContract
     */
    private $storageRepositoryContract;

    /**
     * @var FrontendFactory
     */
    private $frontendFactory;

    /**
     * @var CachingRepository
     */
    private $cachingRepository;

    /**
     * ResetTemplateCacheResource constructor.
     * @param Request $request
     * @param ApiResponse $response
     * @param StorageRepositoryContract $storageRepositoryContract
     */
    public function __construct(
        Request $request,
        ApiResponse $response,
        StorageRepositoryContract $storageRepositoryContract,
        CachingRepository $cachingRepository,
        FrontendFactory $frontendFactory)
    {
        parent::__construct($request, $response);

        $this->storageRepositoryContract = $storageRepositoryContract;
        $this->frontendFactory   = $frontendFactory;
        $this->cachingRepository = $cachingRepository;
    }

    // Post
    /**
     * Add an item to the basket
     * @return Response
     */
    public function store():Response
    {
        echo 'run';
        $objectList = $this->storageRepositoryContract->listObjects(
            'IO',
            'tpl_'
        );
        /** @var SalesPriceService $salesPriceService */
        $salesPriceService = pluginApp(SalesPriceService::class);
        foreach ($objectList->objects as $cacheObject) {
            /** @var StorageObject $cacheObject */
            /** @var StorageObject $object */
            $object = $this->storageRepositoryContract->getObject('IO', 'tpl_' . $cacheObject->key);
            try {
                //check options
                $settings = $this->container->get($object->metaData['templatename']);
                $diff = array_diff_assoc(json_decode($object->metaData['data'], true), $settings->getData());
                if (!empty($diff)) {
                    $this->storageRepositoryContract->deleteObject('IO', 'tpl_' . $cacheObject->key);
                } else {
                    $lang = $object->metaData['language'];
                    $this->frontendFactory->getLocale()->setLanguage($lang);
                    if ($settings->containsItems()) {
                        $itemData = json_decode($object->metaData['itemdata'], true);
                        $salesPriceService
                            ->setCurrency($itemData['currency'])
                            ->setShippingCountryId($itemData['countryId'])
                            ->setClassId($itemData['customerClassId']);
                    }
                    $templateContent = $this->twig->render(
                        $object->metaData['templatename'],
                        [
                            'options' => $settings->getData()
                        ]
                    );
                    $this->storageRepositoryContract->uploadObject(
                        'IO',
                        'tpl_' . $cacheObject->key,
                        $templateContent,
                        false,
                        $object->metaData
                    );
                    if (strlen((STRING)$templateContent) <= ContentCaching::MAX_BYTE_SIZE_FOR_FAST_CACHING) {
                        $smallContentCache          = pluginApp(SmallContentCache::class);
                        $smallContentCache->content = $templateContent;
                        $this->cachingRepository->put(substr('tpl_' . $cacheObject->key, 0, -5), $smallContentCache, 15);
                    } else {
                        $this->cachingRepository->forget(substr('tpl_' . $cacheObject->key, 0, -5));
                    }
                }
            } catch (\Exception $exc) {
                $this->getLogger('RebuildContentCache Job')->error($exc->getMessage());
                $this->storageRepositoryContract->deleteObject('IO', 'tpl_' . $cacheObject->key);
                $this->cachingRepository->forget(substr('tpl_' . $cacheObject->key, 0, -5));
            }
        }
    }
}
