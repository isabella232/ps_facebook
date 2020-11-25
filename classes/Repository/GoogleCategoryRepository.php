<?php

namespace PrestaShop\Module\PrestashopFacebook\Repository;

use Db;
use DbQuery;
use PrestaShop\Module\PrestashopFacebook\Adapter\ConfigurationAdapter;
use PrestaShop\Module\PrestashopFacebook\Config\Config;
use PrestaShopCollection;

class GoogleCategoryRepository
{
    const NO_CHILDREN = 0;
    const HAS_CHILDREN = 1;

    /**
     * @var int
     */
    private $homeCategoryId;

    public function __construct(ConfigurationAdapter $configurationAdapter)
    {
        $this->homeCategoryId = (int) $configurationAdapter->get('PS_HOME_CATEGORY');
    }

    /**
     * @param int $categoryId
     * @param int $googleCategoryId
     * @param $shopId
     * @param bool $isParentCategory
     *
     * @throws \PrestaShopDatabaseException
     */
    public function updateCategoryMatch($categoryId, $googleCategoryId, $shopId, $isParentCategory = false)
    {
        Db::getInstance()->insert(
            'fb_category_match',
            [
                'id_category' => (int) $categoryId,
                'google_category_id' => (int) $googleCategoryId,
                'is_parent_category' => $isParentCategory,
                'id_shop' => (int) $shopId,
            ],
            false,
            true,
            DB::REPLACE
        );
    }

    /**
     * @param PrestaShopCollection $childCategories
     * @param int $googleCategoryId
     * @param $shopId
     *
     * @throws \PrestaShopDatabaseException
     */
    public function updateCategoryChildrenMatch(PrestaShopCollection $childCategories, $googleCategoryId, $shopId)
    {
        $data = [];

        foreach ($childCategories as $category) {
            $data[] = [
                'id_category' => (int) $category->id,
                'google_category_id' => (int) $googleCategoryId,
                'is_parent_category' => false,
                'id_shop' => (int) $shopId,
            ];
        }

        Db::getInstance()->insert(
            'fb_category_match',
            $data,
            false,
            true,
            DB::REPLACE
        );
    }

    /**
     * @param int $categoryId
     * @param $shopId
     *
     * @return int
     */
    public function getGoogleCategoryIdByCategoryId($categoryId, $shopId)
    {
        $sql = new DbQuery();
        $sql->select('google_category_id');
        $sql->from('fb_category_match');
        $sql->where('`id_category` = "' . (int) $categoryId . '"');
        $sql->where('id_shop = ' . (int) $shopId);

        return (int) Db::getInstance()->getValue($sql);
    }

    /**
     * @param int $categoryId
     * @param $shopId
     *
     * @return array|false
     */
    public function getCategoryMatchByCategoryId($categoryId, $shopId)
    {
        $sql = new DbQuery();
        $sql->select('id_category');
        $sql->select('google_category_id');
        $sql->select('is_parent_category');
        $sql->from('fb_category_match');
        $sql->where('`id_category` = "' . (int) $categoryId . '"');
        $sql->where('id_shop = ' . (int) $shopId);

        return Db::getInstance()->getRow($sql);
    }

    /**
     * @param array $categoryIds
     * @param $shopId
     *
     * @return array|false
     *
     * @throws \PrestaShopDatabaseException
     */
    public function getGoogleCategoryIdsByCategoryIds(array $categoryIds, $shopId)
    {
        $sql = new DbQuery();
        $sql->select('google_category_id');
        $sql->from('fb_category_match');
        $sql->where('`id_category` IN ("' . implode('", "', $categoryIds) . '")');
        $sql->where('id_shop = ' . (int) $shopId);

        return Db::getInstance()->executeS($sql);
    }

    /**
     * @param array $categoryIds
     * @param $shopId
     *
     * @return array|false
     *
     * @throws \PrestaShopDatabaseException
     */
    public function getCategoryMatchesByCategoryIds(array $categoryIds, $shopId)
    {
        $sql = new DbQuery();
        $sql->select('id_category');
        $sql->select('google_category_id');
        $sql->select('is_parent_category');
        $sql->from('fb_category_match');
        $sql->where('`id_category` IN ("' . implode('", "', $categoryIds) . '")');
        $sql->where('id_shop = ' . (int) $shopId);

        return Db::getInstance()->executeS($sql);
    }

    public function getFilteredCategories($parentCategoryId, $langId, $offset, $limit, $shopId)
    {
        $sql = new DbQuery();
        $sql->select('c.id_category as shopCategoryId');
        $sql->select('cl.name as shopCategoryName');
        $sql->select('cm.google_category_id as googleCategoryId');
        $sql->select('cm.is_parent_category as isParentCategory');
        $sql->select('case when c.nleft = c.nright -1 and c.`level_depth` = ' . Config::MAX_CATEGORY_DEPTH .
            ' then ' . self::NO_CHILDREN . ' else ' . self::HAS_CHILDREN . ' end deploy');
        $sql->from('category', 'c');
        $sql->innerJoin('category_lang', 'cl', 'c.id_category = cl.id_category AND cl.id_lang = ' . (int) $langId);
        $sql->leftJoin(
            'fb_category_match',
            'cm',
            'cm.id_category = c.id_category AND cm.id_shop = ' . (int) $shopId
        );
        $sql->where(
            'c.`id_parent` = ' . (int) $parentCategoryId . ' OR
            (
                        c.`nleft` > (SELECT pc.`nleft` from `ps_category` as pc WHERE pc.id_category = '
            . (int) $parentCategoryId . ' AND pc.`level_depth` >= ' . Config::MAX_CATEGORY_DEPTH . ') AND
                        c.`nright` < (SELECT pc.`nright` from `ps_category` as pc WHERE pc.id_category = '
            . (int) $parentCategoryId . ' AND pc.`level_depth` >= ' . Config::MAX_CATEGORY_DEPTH . ')
            )
        ');
        $sql->limit($limit, $offset);

        return Db::getInstance()->executeS($sql);
    }

    /**
     * @param $shopId
     *
     * @return bool
     *
     * @throws \PrestaShopDatabaseException
     */
    public function areParentCategoriesMatched($shopId)
    {
        $sql = new DbQuery();
        $sql->select('c.id_category');
        $sql->from('category', 'c');
        $sql->innerJoin('category_shop', 'cs', 'cs.id_category = c.id_category');
        $sql->leftJoin('fb_category_match', 'cm', 'cm.id_category = c.id_category AND cm.id_shop = cs.id_shop');
        $sql->where("c.id_parent = {$this->homeCategoryId} AND cm.google_category_id IS NULL");
        $sql->where('cs.id_shop = ' . (int) $shopId);

        return (bool) Db::getInstance()->executeS($sql);
    }
}
