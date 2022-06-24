<?php
declare(strict_types=1);

namespace Codific\GoogleDocsHelpBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

class CodificGoogleDocsHelpExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.xml');

        $configuration = $this->getConfiguration($configs, $container);
        $config = $this->processConfiguration($configuration, $configs);

        $definition = $container->getDefinition('codific_google_docs_help.service.google_docs_client_service');
        $definition->setArgument(1, $config['enabled']);
        $definition->setArgument(2, $config['credentials']);
        $definition->setArgument(3, $config['documents']);
    }
}