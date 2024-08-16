<?php

declare(strict_types=1);

namespace Lyrasoft\Security;

use Lyrasoft\Security\Command\DbExcelCommand;
use Lyrasoft\Security\Command\ShowDsnCommand;
use Windwalker\Core\Application\AppClient;
use Windwalker\Core\Application\ApplicationInterface;
use Windwalker\Core\Package\AbstractPackage;
use Windwalker\Core\Package\PackageInstaller;
use Windwalker\DI\Container;
use Windwalker\DI\ServiceProviderInterface;

class SecurityPackage extends AbstractPackage implements ServiceProviderInterface
{
    public function __construct(protected ApplicationInterface $app)
    {
    }

    public function register(Container $container): void
    {
        if ($this->app->getClient() === AppClient::CONSOLE) {
            $container->mergeParameters(
                'commands',
                [
                    'db:dsn' => ShowDsnCommand::class,
                    'db:excel' => DbExcelCommand::class,
                ]
            );
        }
    }

    public function install(PackageInstaller $installer): void
    {
        $installer->installConfig(__DIR__ . '/../etc/*.php', 'config');
    }
}
