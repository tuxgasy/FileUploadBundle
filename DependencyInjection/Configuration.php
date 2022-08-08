<?php

namespace TuxGasy\FileUploadBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('file_upload');
        $rootNode
            ->children()
                ->scalarNode('dir')->defaultValue('%kernel.project_dir%/var/upload')->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
