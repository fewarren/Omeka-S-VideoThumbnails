<?php declare(strict_types=1);

namespace DerivativeMedia\Media\FileRenderer;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Media\FileRenderer\RendererInterface;

/**
 * @see \Omeka\Media\FileRenderer\AudioRenderer
 */
class AudioRenderer implements RendererInterface
{
    const DEFAULT_OPTIONS = [
        'controls' => true,
    ];

    public function render(
        PhpRenderer $view,
        MediaRepresentation $media,
        array $options = []
    ) {
        $options = array_merge(self::DEFAULT_OPTIONS, $options);

        // Use a format compatible with html5 and xhtml.
        $escapeAttr = $view->plugin('escapeHtmlAttr');

        $sources = '';
        $source = '<source src="%s" type="%s"/>' . "\n";
        $originalUrl = $media->originalUrl();

        $data = $media->mediaData();
        $hasDerivative = isset($data['derivative']) && count($data['derivative']);
        if ($hasDerivative) {
            // FIXED: Use serverUrl helper directly instead of accessing service locator
            $serverUrl = $view->serverUrl();
            $basePath = $serverUrl . '/download/files';

            foreach ($data['derivative'] as $folder => $derivative) {
                $sources .= sprintf($source,
                    $escapeAttr($basePath . '/' . $folder . '/' . $derivative['filename']),
                    empty($derivative['type']) ? '' : $derivative['type']
                );
            }
            // Append the original file if wanted.
            if ($view->setting('derivativemedia_append_original_audio', false)) {
                // FIXED: Use original URL directly - it already has correct hostname
                $sources .= sprintf($source, $escapeAttr($originalUrl), $media->mediaType());
            }
        } else {
            // FIXED: Use original URL directly - it already has correct hostname
            $sources .= sprintf($source, $escapeAttr($originalUrl), $media->mediaType());
        }

        $attrs = ' title="' . $escapeAttr($media->displayTitle()) . '"';

        if (!empty($options['autoplay'])) {
            $attrs .= ' autoplay="autoplay"';
        }
        if (!empty($options['controls'])) {
            $attrs .= ' controls="controls"';
        }
        if (!empty($options['loop'])) {
            $attrs .= ' loop="loop"';
        }
        if (!empty($options['muted'])) {
            $attrs .= ' muted="muted"';
        }
        if (isset($options['class']) && $options['class']) {
            $attrs .= sprintf(' class="%s"', $escapeAttr($options['class']));
        }
        if (isset($options['preload']) && $options['preload']) {
            $attrs .= sprintf(' preload="%s"', $escapeAttr($options['preload']));
        }

        return sprintf('<audio%s>
    %s
    %s
</audio>',
            $attrs,
            $sources,
            sprintf(
                $view->translate('Your browser does not support HTML5 audio, but you can download it: %s.'), // @translate
                $view->hyperlink($media->filename(), $originalUrl)
            )
        );
    }
}
