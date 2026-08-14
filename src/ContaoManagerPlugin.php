<?php

declare(strict_types=1);

namespace Netzhirsch\ContaoMcpBundle;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;

class ContaoManagerPlugin implements BundlePluginInterface, RoutingPluginInterface
{
    /**
     * @return list<BundleConfig>
     */
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(ContaoMcpBundle::class)
                ->setLoadAfter([ContaoCoreBundle::class]),
        ];
    }

    /**
     * Wires up the bundle's `config/routes.yaml` so Symfony finds the
     * #[Route] attributes on our OAuth controllers (RegisterController,
     * AuthorizeController, TokenController, MetadataController).
     */
    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): ?RouteCollection
    {
        $file = \dirname(__DIR__).'/config/routes.yaml';
        if (!is_file($file)) {
            return null;
        }
        $loader = $resolver->resolve($file);

        return $loader ? $loader->load($file) : null;
    }
}
