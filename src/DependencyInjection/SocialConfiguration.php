<?php

namespace Base\Social\DependencyInjection;

use Base\Bundle\AbstractBaseConfiguration;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class SocialConfiguration extends AbstractBaseConfiguration
{
    private bool $childrenDeclared = false;

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = $this->getTreeBuilder();
        if ($this->childrenDeclared) {
            return $treeBuilder;
        }
        $this->childrenDeclared = true;

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('providers')
                    ->info('The omnipost providers the site uses, by name, in the order the back office lists them.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['instagram'])
                ->end()
                ->arrayNode('ffmpeg')->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('binary')->defaultValue('ffmpeg')->info('The ffmpeg binary: a name on the PATH, or a path.')->end()
                        ->scalarNode('ffprobe')->defaultValue('ffprobe')->info('The ffprobe binary.')->end()
                        ->integerNode('crf')->min(0)->max(51)->defaultValue(20)->info('x264 quality: lower is finer and heavier.')->end()
                        ->integerNode('fps')->min(1)->max(60)->defaultValue(30)->info('Frames per second of a rendered reel.')->end()
                        ->integerNode('timeout')->min(10)->defaultValue(900)->info('Seconds a rendering may take.')->end()
                        ->scalarNode('font')->defaultValue('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf')->info('The band\'s font when the template has none.')->end()
                    ->end()
                ->end()
                ->scalarNode('storage')->defaultValue('%kernel.project_dir%/var/storage/social')
                    ->info('Where the rendered reels and their covers are kept: <storage>/<post id>/<platform>.mp4.')->end()
                ->arrayNode('feed')->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('limit')->min(1)->max(100)->defaultValue(24)->info('Posts read from the account at each sync.')->end()
                        ->booleanNode('cache_thumbnails')->defaultTrue()->info('A local copy of each thumbnail: the networks\' own addresses expire.')->end()
                    ->end()
                ->end()
                ->integerNode('media_url_ttl')->min(60)->defaultValue(86400)
                    ->info('Seconds the public address of a rendered file still answers once its publication is over.')->end()
                ->arrayNode('check')->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('delay')->min(1)->defaultValue(30)->info('Seconds between two looks at a publication the network is still processing.')->end()
                        ->integerNode('attempts')->min(1)->defaultValue(20)->info('Looks before giving up.')->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
