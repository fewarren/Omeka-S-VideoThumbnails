<?php declare(strict_types=1);

namespace DerivativeMedia\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;

/**
 * Delegator that conditionally returns the module's video-aware thumbnailer
 * when the setting derivativemedia_use_custom_thumbnailer is enabled; otherwise
 * returns the core thumbnailer instance produced by the original factory.
 */
class ConditionalThumbnailerDelegator implements DelegatorFactoryInterface
{
    /**
     * @param ContainerInterface $container
     * @param string $name Service name (Omeka\\File\\Thumbnailer)
     * @param callable $callback Callback to create the original service
     * @param null|array $options
     * @return mixed
     */
    public function __invoke(ContainerInterface $container, $name, callable $callback, array $options = null)
    {
        // Default to original thumbnailer
        $original = $callback();

        try {
            $settings = $container->get('Omeka\\Settings');
            $useCustom = (bool) $settings->get('derivativemedia_use_custom_thumbnailer', false);

            // Optional debug logging
            if ($container->has('DerivativeMedia\\Service\\DebugManager')) {
                $dbg = $container->get('DerivativeMedia\\Service\\DebugManager');
                $dbg->logInfo('ConditionalThumbnailerDelegator: setting use_custom=' . ($useCustom ? 'true' : 'false'), DebugManager::COMPONENT_FACTORY);
            }

            if ($useCustom) {
                // Return the module's video-aware thumbnailer instead of core
                if ($container->has('DerivativeMedia\\File\\Thumbnailer\\VideoAwareThumbnailer')) {
                    $custom = $container->get('DerivativeMedia\\File\\Thumbnailer\\VideoAwareThumbnailer');
                    if (isset($dbg)) {
                        $dbg->logInfo('ConditionalThumbnailerDelegator: returning VideoAwareThumbnailer (' . \get_class($custom) . ')', DebugManager::COMPONENT_FACTORY);
                    }
                    return $custom;
                }
            }
        } catch (\Throwable $e) {
            // Swallow errors and fall back to original
            if ($container->has('DerivativeMedia\\Service\\DebugManager')) {
                $dbg = $container->get('DerivativeMedia\\Service\\DebugManager');
                $dbg->logWarning('ConditionalThumbnailerDelegator error: ' . $e->getMessage(), DebugManager::COMPONENT_FACTORY);
            }
        }

        if (isset($dbg)) {
            $dbg->logInfo('ConditionalThumbnailerDelegator: returning original thumbnailer (' . \get_class($original) . ')', DebugManager::COMPONENT_FACTORY);
        }
        return $original;
    }
}

