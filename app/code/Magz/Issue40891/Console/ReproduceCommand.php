<?php
/**
 * CLI probe for github.com/magento/magento2/issues/40891
 *
 * "Product returns 404 when opened without category context on PHP 8.5
 *  (lastVisitedCategoryId is null)".
 *
 * This exercises the exact chain the issue blames, with the exact null input:
 *   Helper\Product::initProduct() no-category branch
 *     -> Catalog\Model\Session::getLastVisitedCategoryId() (null on a fresh/cleared session)
 *       -> Product::canBeShowInCategory(null)
 *         -> ResourceModel\Product::canBeShowInCategory($product, null)
 *
 * The bug only surfaces at the interception boundary: this module also registers
 * SimulateElasticsuiteCategoryPlugin (aroundCanBeShowInCategory typed `int`, per
 * the core docblock). With that plugin active, passing null throws a TypeError —
 * exactly what issue #40891 reports. Without such a plugin, core's own resource
 * model swallows the null via (int) casting, so nothing appears to break.
 *
 * Run it on a real 2.4.9 + PHP 8.5 instance.
 */
declare(strict_types=1);

namespace Magz\Issue40891\Console;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Product as ProductHelper;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReproduceCommand extends Command
{
    /**
     * @param ProductRepositoryInterface $productRepository
     * @param ProductHelper $productHelper
     * @param State $appState
     * @param string|null $name
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductHelper $productHelper,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('magz:issue40891:repro')
            ->setDescription(
                'Probe issue #40891: product visibility without category context (null last-visited category).'
            )
            ->addArgument('product_id', InputArgument::REQUIRED, 'Product entity ID to test (must be enabled).');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Behave like a storefront request (store scope, visibility rules).
        try {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
        } catch (\Throwable $e) {
            // Area already set for this process; safe to ignore.
        }

        $productId = (int) $input->getArgument('product_id');
        $output->writeln(sprintf('PHP version: <info>%s</info>', PHP_VERSION));

        try {
            $product = $this->productRepository->getById($productId);
        } catch (\Throwable $e) {
            $output->writeln('<error>Cannot load product ' . $productId . ': ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // On a fresh/cleared session with no category context this is exactly what
        // Catalog\Model\Session::getLastVisitedCategoryId() returns.
        $lastVisitedCategoryId = null;
        $output->writeln('Simulated last-visited category id: <comment>null</comment>');

        // (1) The precise call the issue reports. With the bundled ElasticSuite-style
        //     plugin active this raises a TypeError (null given, int expected).
        try {
            $rawResult = $product->canBeShowInCategory($lastVisitedCategoryId);
            $output->writeln(
                'canBeShowInCategory(null) => ' . var_export($rawResult, true)
                . '  <info>(no error — no int-typed plugin present, or core already guards null)</info>'
            );
        } catch (\TypeError $e) {
            $output->writeln('<error>REPRODUCED — TypeError from a plugin on canBeShowInCategory(null):</error>');
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            $output->writeln($e->getTraceAsString());
            $output->writeln(
                '<comment>Root cause: core (Helper\Product::initProduct / Model\Design) passes null into '
                . 'canBeShowInCategory(), which documents @param int. Fix: cast/guard before the call (see #40890).</comment>'
            );
            return Command::FAILURE;
        }

        // (2) Reproduce Helper\Product::initProduct()'s no-category branch resolution.
        //     Controller passes (int) getParam('category', false) === 0 when no context.
        $categoryParam = 0;
        $resolvedCategoryId = null;
        if (!$categoryParam && $categoryParam !== false) {
            if ($product->canBeShowInCategory($lastVisitedCategoryId)) {
                $resolvedCategoryId = $lastVisitedCategoryId;
            }
        }
        $output->writeln('Resolved category id (initProduct branch): ' . var_export($resolvedCategoryId, true));

        // (3) The controller only 404s when the product itself is not viewable.
        $canShow = $this->productHelper->canShow($product);
        $output->writeln('Helper\\Product::canShow(product): ' . var_export($canShow, true));

        if ($canShow) {
            $output->writeln(
                '<info>RESULT: product is viewable without category context — issue #40891 does NOT reproduce.</info>'
            );
            return Command::SUCCESS;
        }

        $output->writeln(
            '<comment>RESULT: product not viewable due to VISIBILITY (isVisibleInCatalog/SiteVisibility), '
            . 'not category context or PHP 8.5. This is the real, longstanding cause of a direct-access 404.</comment>'
        );
        return Command::SUCCESS;
    }
}
