<?php
/**
 * Reproduction plugin for github.com/magento/magento2/issues/40891.
 *
 * Mirrors third-party plugins (e.g. Smile ElasticSuite —
 * github.com/Smile-SA/elasticsuite/pull/3874) that add an
 * `aroundCanBeShowInCategory` interceptor and trust the published contract of
 * Magento\Catalog\Model\Product::canBeShowInCategory(), whose docblock declares
 * `@param int $categoryId` (see 2.4.9 Product.php line 2132).
 *
 * Core violates that contract: Helper\Product::initProduct() (line 443) and
 * Model\Design::getDesignSettings() read Catalog session's
 * getLastVisitedCategoryId(), which is NULL when a product is opened with no
 * category context, and pass that null straight into canBeShowInCategory().
 * Core's own resource model survives because it does `(int)$categoryId`, but a
 * conforming plugin that types the argument as `int` gets `null` at the
 * interception boundary -> TypeError -> caught by the product-view controller's
 * catch-all -> 404.
 */
declare(strict_types=1);

namespace Magz\Issue40891\Plugin;

use Magento\Catalog\Model\Product;

class SimulateElasticsuiteCategoryPlugin
{
    /**
     * Typed `int` exactly as the core docblock promises. When core passes null
     * (no category context), PHP raises a TypeError at argument binding — before
     * this body ever runs — which is the crash issue #40891 reports.
     *
     * @param Product $subject
     * @param callable $proceed
     * @param int $categoryId
     * @return string|false
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundCanBeShowInCategory(Product $subject, callable $proceed, int $categoryId)
    {
        // Secondary hazard the issue also mentions: using the id as an array key.
        // With a null $categoryId this would silently coerce to the "" key (and is
        // deprecated on newer PHP), corrupting per-category caches:
        //   static $seen = []; $seen[$categoryId] = true;
        return $proceed($categoryId);
    }
}
