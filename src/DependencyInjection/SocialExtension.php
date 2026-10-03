<?php

namespace Base\Social\DependencyInjection;

use Base\Bundle\AbstractBaseExtension;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class SocialExtension extends AbstractBaseExtension
{
    public function getConfiguration(array $config, ContainerBuilder $container): SocialConfiguration
    {
        return new SocialConfiguration();
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.php');

        $configuration = new SocialConfiguration();
        $config = (new Processor())->processConfiguration($configuration, $configs);

        // Flat parameters: social.providers, social.ffmpeg.binary, social.check.delay...
        $this->setConfiguration($container, $config, $configuration->getTreeBuilder()->buildTree()->getName());
    }
}
