<?php
declare(strict_types=1);

namespace Codific\GoogleDocsHelpBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('codific_google_docs_help');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->arrayNode('credentials')
                    ->children()
                        ->scalarNode('type')->end()
                        ->scalarNode('project_id')->end()
                        ->scalarNode('private_key_id')->end()
                        ->scalarNode('private_key')->end()
                        ->scalarNode('client_email')->end()
                        ->scalarNode('client_id')->end()
                        ->scalarNode('auth_uri')->end()
                        ->scalarNode('token_uri')->end()
                        ->scalarNode('auth_provider_x509_cert_url')->end()
                        ->scalarNode('client_x509_cert_url')->end()
                    ->end()
                ->end()
                ->arrayNode('documents')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('locale')->end()
                            ->scalarNode('admin_doc_id')->defaultNull()->end()
                            ->scalarNode('client_doc_id')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
        return $treeBuilder;
    }
}