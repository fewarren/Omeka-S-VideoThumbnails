<?php declare(strict_types=1);

namespace DerivativeMedia\Site\BlockLayout;

use DerivativeMedia\Service\VideoThumbnailService;
use DerivativeMedia\Service\DebugManager;
use DerivativeMedia\Form\VideoThumbnailBlockForm;
use Laminas\Form\FormElementManager;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Manager;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Omeka\Stdlib\ErrorStore;
use Omeka\Job\Dispatcher as JobDispatcher;

class VideoThumbnail extends AbstractBlockLayout
{
    /**
     * @var VideoThumbnailService
     */
    protected $videoThumbnailService;

    /**
     * @var FormElementManager
     */
    protected $formElementManager;

    /**
     * @var Manager
     */
    protected $apiManager;

    /**
     * @var DebugManager
     */
    protected $debugManager;

    /**
     * @var JobDispatcher
     */
    protected $jobDispatcher;

    public function __construct(
        VideoThumbnailService $videoThumbnailService,
        FormElementManager $formElementManager,
        Manager $apiManager,
        DebugManager $debugManager,
        JobDispatcher $jobDispatcher
    ) {
        $this->videoThumbnailService = $videoThumbnailService;
        $this->formElementManager = $formElementManager;
        $this->apiManager = $apiManager;
        $this->debugManager = $debugManager;
        $this->jobDispatcher = $jobDispatcher;
    }

    public function getLabel()
    {
        return 'Video Thumbnail'; // @translate
    }

    public function onHydrate(\Omeka\Entity\SitePageBlock $block, ErrorStore $errorStore): void
    {
        $data = $block->getData();

        // Validate selected media (legacy array support)
        if (isset($data['media']) && is_array($data['media'])) {
            $validMediaIds = [];
            foreach ($data['media'] as $mediaId) {
                if (is_numeric($mediaId)) {
                    $validMediaIds[] = (int) $mediaId;
                }
            }
            $data['media'] = $validMediaIds;
            $block->setData($data);
        }

        // NEW: If an override percentage is provided for a selected media, dispatch a
        // regeneration job on save so the new thumbnail is created at that position.
        try {
            $mediaId = $data['media_id'] ?? null;
            $override = $data['override_percentage'] ?? null;

            if ($mediaId && $override !== null && $override !== '') {
                $intMediaId = (int) $mediaId;
                $intPercentage = (int) $override;

                // Clamp to [0, 100]
                if ($intPercentage < 0) { $intPercentage = 0; }
                if ($intPercentage > 100) { $intPercentage = 100; }

                $opId = 'block_onHydrate_' . uniqid();
                $this->debugManager->logInfo(
                    sprintf('Dispatching GenerateVideoThumbnails job for media #%d at %d%% (force)', $intMediaId, $intPercentage),
                    DebugManager::COMPONENT_BLOCK,
                    $opId
                );

                $this->jobDispatcher->dispatch('DerivativeMedia\Job\GenerateVideoThumbnails', [
                    'media_id' => $intMediaId,
                    'percentage' => $intPercentage,
                    'force_regenerate' => true,
                ]);
            }
        } catch (\Exception $e) {
            $this->debugManager->logError('Failed to dispatch regen job on block save: ' . $e->getMessage(), DebugManager::COMPONENT_BLOCK);
            // Do not block saving the page if dispatch fails
        }
    }

    public function form(PhpRenderer $view, SiteRepresentation $site,
        SitePageRepresentation $page = null, SitePageBlockRepresentation $block = null
    ) {
        // CONFIGURABLE LOGGING FIX: Use DebugManager instead of direct error_log

        $opId = 'block_form_' . uniqid();
        $this->debugManager->traceBlockForm($opId, $block);

        try {
            $this->debugManager->logInfo('Getting VideoThumbnailBlockForm from FormElementManager', DebugManager::COMPONENT_BLOCK, $opId);
            // CONFIGURABLE LOGGING FIX: Use DebugManager instead of direct error_log
            $this->debugManager->logInfo('About to get form from FormElementManager', DebugManager::COMPONENT_BLOCK, $opId);
            $form = $this->formElementManager->get(VideoThumbnailBlockForm::class);

            // CRITICAL: Initialize the form before using it
            $this->debugManager->logInfo('Initializing form', DebugManager::COMPONENT_BLOCK, $opId);
            $form->init();

            // Populate media options with video files - CRITICAL: Do this in form method, not factory
            try {
                $this->debugManager->logInfo('Loading video media for dropdown', DebugManager::COMPONENT_BLOCK, $opId);
                $this->debugManager->logInfo(sprintf('Site context: ID=%d, slug=%s', $site->id(), method_exists($site, 'slug') ? $site->slug() : 'N/A'), DebugManager::COMPONENT_BLOCK, $opId);

                $videoMedia = [];

                // Attempt A: media assigned to this site (per_page)
                $queryA = [
                    'site_id' => $site->id(),
                    'sort_by' => 'title',
                    'sort_order' => 'asc',
                    'per_page' => 200,
                ];
                try {
                    $this->debugManager->logInfo('Query A (media, per_page) = ' . json_encode($queryA), DebugManager::COMPONENT_BLOCK, $opId);
                    $responseA = $this->apiManager->search('media', $queryA);
                    $contentA = $responseA->getContent();
                    $this->debugManager->logInfo('Query A returned ' . count($contentA) . ' media entries', DebugManager::COMPONENT_BLOCK, $opId);

                    // Robust candidate detection: video/* MIME OR video-like file extension
                    $videoExtensions = ['mp4','m4v','mov','avi','mkv','webm','wmv','mpg','mpeg','m2ts'];
                    $extFromMedia = function($m) {
                        $url = method_exists($m, 'originalUrl') ? (string)$m->originalUrl() : '';
                        if (!$url && method_exists($m, 'source')) { $url = (string)$m->source(); }
                        if (!$url && method_exists($m, 'filename')) { $url = (string)$m->filename(); }
                        $path = parse_url($url, PHP_URL_PATH) ?: $url;
                        $ext = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
                        return $ext;
                    };
                    $isCandidate = function($m) use ($videoExtensions, $extFromMedia) {
                        $t = (string)$m->mediaType();
                        if ($t && strpos($t, 'video/') === 0) { return true; }
                        $ext = $extFromMedia($m);
                        if ($ext && in_array($ext, $videoExtensions, true)) { return true; }
                        return false;
                    };

                    $preCount = count($contentA);
                    $videoMedia = array_values(array_filter($contentA, $isCandidate));
                    $postCount = count($videoMedia);
                    $this->debugManager->logInfo("Query A candidates: $postCount / $preCount (after filtering)", DebugManager::COMPONENT_BLOCK, $opId);
                    if ($postCount === 0 && $preCount > 0) {
                        $sampleMiss = array_slice($contentA, 0, 5);
                        foreach ($sampleMiss as $idx => $mm) {
                            $this->debugManager->logInfo(sprintf('A-miss[%d]: id=%d type=%s ext=%s', $idx, $mm->id(), (string)$mm->mediaType(), $extFromMedia($mm)), DebugManager::COMPONENT_BLOCK, $opId);
                        }
                    }

                    // Targeted diagnostics for missing media (e.g., 464)
                    $targetId = 464;
                    $idsA = array_map(function($m){ return $m->id(); }, $contentA);
                    $idsCand = array_map(function($m){ return $m->id(); }, $videoMedia);
                    $inA = in_array($targetId, $idsA, true);
                    $inCand = in_array($targetId, $idsCand, true);
                    $this->debugManager->logInfo(sprintf('Target #%d presence: inQueryA=%s, inCandidates=%s', $targetId, $inA ? 'yes' : 'no', $inCand ? 'yes' : 'no'), DebugManager::COMPONENT_BLOCK, $opId);
                    if ($inA && !$inCand) {
                        foreach ($contentA as $mm) {
                            if ($mm->id() === $targetId) {
                                $extT = $extFromMedia($mm);
                                $details = sprintf('Target #%d excluded. type=%s ext=%s ingester=%s renderer=%s hasOriginal=%s originalUrl=%s source=%s',
                                    $targetId,
                                    (string)$mm->mediaType(),
                                    $extT ?: 'null',
                                    method_exists($mm, 'ingester') ? (string)$mm->ingester() : 'n/a',
                                    method_exists($mm, 'renderer') ? (string)$mm->renderer() : 'n/a',
                                    method_exists($mm, 'hasOriginal') ? ((bool)$mm->hasOriginal() ? 'yes' : 'no') : 'n/a',
                                    method_exists($mm, 'originalUrl') ? ((string)$mm->originalUrl() ?: 'null') : 'n/a',
                                    method_exists($mm, 'source') ? ((string)$mm->source() ?: 'null') : 'n/a'
                                );
                                $this->debugManager->logWarning($details, DebugManager::COMPONENT_BLOCK, $opId);
                                break;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $this->debugManager->logError('Query A failed: ' . $e->getMessage(), DebugManager::COMPONENT_BLOCK, $opId);
                }

                // Attempt B: if none found, try using limit instead of per_page
                if (empty($videoMedia)) {
                    $queryB = [
                        'site_id' => $site->id(),
                        'sort_by' => 'title',
                        'sort_order' => 'asc',
                        'limit' => 200,
                    ];
                    try {
                        $this->debugManager->logInfo('Query B (media, limit) = ' . json_encode($queryB), DebugManager::COMPONENT_BLOCK, $opId);
                        $responseB = $this->apiManager->search('media', $queryB);
                        $contentB = $responseB->getContent();
                        $this->debugManager->logInfo('Query B returned ' . count($contentB) . ' media entries', DebugManager::COMPONENT_BLOCK, $opId);
                        $preCount = count($contentB);
                        $videoMedia = array_values(array_filter($contentB, $isCandidate));
                        $postCount = count($videoMedia);
                        $this->debugManager->logInfo("Query B candidates: $postCount / $preCount (after filtering)", DebugManager::COMPONENT_BLOCK, $opId);
                        if ($postCount === 0 && $preCount > 0) {
                            $sampleMiss = array_slice($contentB, 0, 5);
                            foreach ($sampleMiss as $idx => $mm) {
                                $this->debugManager->logInfo(sprintf('B-miss[%d]: id=%d type=%s ext=%s', $idx, $mm->id(), (string)$mm->mediaType(), $extFromMedia($mm)), DebugManager::COMPONENT_BLOCK, $opId);
                            }
                        }
                    } catch (\Exception $e) {
                        $this->debugManager->logError('Query B failed: ' . $e->getMessage(), DebugManager::COMPONENT_BLOCK, $opId);
                    }
                }

                // Attempt C: if still none, global media search (per_page)
                if (empty($videoMedia)) {
                    $this->debugManager->logWarning('No site-assigned video media found; falling back to global search (per_page)', DebugManager::COMPONENT_BLOCK, $opId);
                    $queryC = [ 'sort_by' => 'title', 'sort_order' => 'asc', 'per_page' => 200 ];
                    try {
                        $this->debugManager->logInfo('Query C (media, per_page) = ' . json_encode($queryC), DebugManager::COMPONENT_BLOCK, $opId);
                        $responseC = $this->apiManager->search('media', $queryC);
                        $contentC = $responseC->getContent();
                        $this->debugManager->logInfo('Query C returned ' . count($contentC) . ' media entries', DebugManager::COMPONENT_BLOCK, $opId);
                        $preCount = count($contentC);
                        $videoMedia = array_values(array_filter($contentC, $isCandidate));
                        $postCount = count($videoMedia);
                        $this->debugManager->logInfo("Query C candidates: $postCount / $preCount (after filtering)", DebugManager::COMPONENT_BLOCK, $opId);
                        if ($postCount === 0 && $preCount > 0) {
                            $sampleMiss = array_slice($contentC, 0, 5);
                            foreach ($sampleMiss as $idx => $mm) {
                                $this->debugManager->logInfo(sprintf('C-miss[%d]: id=%d type=%s ext=%s', $idx, $mm->id(), (string)$mm->mediaType(), $extFromMedia($mm)), DebugManager::COMPONENT_BLOCK, $opId);
                            }
                        }
                    } catch (\Exception $e) {
                        $this->debugManager->logError('Query C failed: ' . $e->getMessage(), DebugManager::COMPONENT_BLOCK, $opId);
                    }
                }

                // Attempt D: if still none, discover via items attached to site
                if (empty($videoMedia)) {
                    $this->debugManager->logWarning('No video media found via media search; trying item->media discovery', DebugManager::COMPONENT_BLOCK, $opId);
                    $itemsQuery = [ 'site_id' => $site->id(), 'per_page' => 50, 'page' => 1 ];
                    try {
                        $this->debugManager->logInfo('Items query = ' . json_encode($itemsQuery), DebugManager::COMPONENT_BLOCK, $opId);
                        $itemsResp = $this->apiManager->search('items', $itemsQuery);
                        $items = $itemsResp->getContent();
                        $this->debugManager->logInfo('Items query returned ' . count($items) . ' item(s)', DebugManager::COMPONENT_BLOCK, $opId);
                        foreach ($items as $item) {
                            $mediaList = $item->media();
                            if ($mediaList) {
                                foreach ($mediaList as $m) {
                                    $t = $m->mediaType();
                                    if ($t && strpos($t, 'video/') === 0) {
                                        $videoMedia[] = $m;
                                    }
                                }
                            }
                        }
                        $this->debugManager->logInfo('Collected ' . count($videoMedia) . ' video media via item traversal', DebugManager::COMPONENT_BLOCK, $opId);
                    } catch (\Exception $e) {
                        $this->debugManager->logError('Items/media traversal failed: ' . $e->getMessage(), DebugManager::COMPONENT_BLOCK, $opId);
                    }
                }

                // Log a sample of discovered media for diagnostics
                $sample = array_slice($videoMedia, 0, 10);
                foreach ($sample as $idx => $m) {
                    $this->debugManager->logInfo(sprintf('Media[%d]: id=%d type=%s title=%s', $idx, $m->id(), (string)$m->mediaType(), (string)$m->displayTitle()), DebugManager::COMPONENT_BLOCK, $opId);
                }

                $mediaOptions = ['' => 'Select a video...'];
                foreach ($videoMedia as $media) {
                    $title = $media->displayTitle() ?: $media->source();
                    $mediaOptions[$media->id()] = sprintf('%s (ID: %d)', $title, $media->id());
                }

                // Ensure currently-selected media appears even if not in the first batch
                if ($block) {
                    $data = $block->data();
                    if (!empty($data['media_id']) && !isset($mediaOptions[$data['media_id']])) {
                        $mediaId = (int) $data['media_id'];
                        try {
                            $mediaRep = $this->apiManager->read('media', $mediaId)->getContent();
                            if ($mediaRep) {
                                $title = $mediaRep->displayTitle() ?: $mediaRep->source();
                                $mediaOptions[$mediaId] = sprintf('%s (ID: %d)', $title, $mediaId);
                                $this->debugManager->logInfo(sprintf('Added current selection to options: media #%d', $mediaId), DebugManager::COMPONENT_BLOCK, $opId);
                            }
                        } catch (\Exception $e) {
                            $this->debugManager->logWarning(sprintf('Could not add current selection (media #%d) to options: %s', $mediaId, $e->getMessage()), DebugManager::COMPONENT_BLOCK, $opId);
                        }
                    }
                }

                $this->debugManager->logInfo(sprintf('Prepared %d video media option(s)', max(0, count($mediaOptions) - 1)), DebugManager::COMPONENT_BLOCK, $opId);

                // Update the media_id element with populated options
                $mediaElement = $form->get('media_id');
                $mediaElement->setValueOptions($mediaOptions);

            } catch (\Exception $e) {
                // If we can't load media, just use empty options
                $this->debugManager->logError('Error loading video media for block form: ' . $e->getMessage(), DebugManager::COMPONENT_BLOCK, $opId);
            }

            if ($block) {
                $data = $block->data();
                // Backward compatibility: map legacy 'template' to new 'display_mode' for editing
                if (!isset($data['display_mode']) && isset($data['template'])) {
                    $data['display_mode'] = $data['template'];
                }
                $this->debugManager->logInfo(sprintf('Setting form data from block (normalized): %s', json_encode($data)), DebugManager::COMPONENT_BLOCK, $opId);
                $form->setData($data);
            } else {
                $this->debugManager->logInfo('No block data to set (new block)', DebugManager::COMPONENT_BLOCK, $opId);
            }

            $this->debugManager->logInfo('Rendering form partial', DebugManager::COMPONENT_BLOCK, $opId);
            return $view->partial('common/block-layout/video-thumbnail-form', [
                'form' => $form,
                'block' => $block,
                'site' => $site,
            ]);
        } catch (\Exception $e) {
            $this->debugManager->logError(sprintf('Exception in form method: %s', $e->getMessage()), DebugManager::COMPONENT_BLOCK, $opId);
            throw $e;
        }
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block) {
        $opId = 'block_render_' . uniqid();

        // CONFIGURABLE LOGGING FIX: Use DebugManager instead of direct error_log
        $this->debugManager->logInfo('VideoThumbnail::render() called', DebugManager::COMPONENT_BLOCK, $opId);
        $this->debugManager->logInfo('Block data: ' . json_encode($block->data()), DebugManager::COMPONENT_BLOCK, $opId);
        $this->debugManager->logInfo('View class: ' . get_class($view), DebugManager::COMPONENT_BLOCK, $opId);

        $data = $block->data();

        // ENHANCEMENT: Log caption choice for debugging
        $blockHeading = isset($data['heading']) && !empty(trim($data['heading'])) ? trim($data['heading']) : '';
        $mediaId = $data['media_id'] ?? null;
        if ($mediaId) {
            $captionSource = !empty($blockHeading) ? 'custom heading' : 'file name';
            $this->debugManager->logInfo(
                sprintf('Video thumbnail block for media #%d using %s as caption: "%s"',
                    $mediaId,
                    $captionSource,
                    !empty($blockHeading) ? $blockHeading : 'N/A'
                ),
                DebugManager::COMPONENT_BLOCK,
                $opId
            );
        }

        // CRITICAL FIX: Get site context for proper URL generation
        $site = $view->vars()->offsetGet('site');

        return $view->partial('common/block-layout/video-thumbnail', [
            'block' => $block,
            'data' => $data,
            'site' => $site, // Pass site context to template
            'videoThumbnailService' => $this->videoThumbnailService,
        ]);
    }

    /**
     * Process data from the block form.
     * CRITICAL METHOD: This method is essential for block data persistence.
     * Without this method, block data is not properly saved.
     *
     * @param array $data The form data
     * @return array The processed data
     */
    public function handleFormData(array $data)
    {
        $blockData = [];

        // Process media_id field - CRITICAL for video persistence
        if (array_key_exists('media_id', $data)) {
            if (empty($data['media_id']) || $data['media_id'] === '0') {
                $blockData['media_id'] = null;
            } else {
                $blockData['media_id'] = (int)$data['media_id'];
            }
        } else {
            $blockData['media_id'] = null;
        }

        // Process override_percentage field (primary field)
        if (array_key_exists('override_percentage', $data)) {
            if ($data['override_percentage'] === '' || $data['override_percentage'] === null) {
                $blockData['override_percentage'] = null;
            } elseif (is_numeric($data['override_percentage'])) {
                $intVal = (int)$data['override_percentage'];
                if ($intVal < 0 || $intVal > 100) {
                    throw new \InvalidArgumentException('Thumbnail Position (%) must be between 0 and 100.');
                }
                $blockData['override_percentage'] = $intVal;
            } else {
                $blockData['override_percentage'] = null;
            }
        } elseif (array_key_exists('percentage', $data)) {
            // Handle legacy 'percentage' field for backward compatibility
            if ($data['percentage'] === '' || $data['percentage'] === null) {
                $blockData['override_percentage'] = null;
            } elseif (is_numeric($data['percentage'])) {
                $intVal = (int)$data['percentage'];
                if ($intVal < 0 || $intVal > 100) {
                    throw new \InvalidArgumentException('Thumbnail Position (%) must be between 0 and 100.');
                }
                $blockData['override_percentage'] = $intVal;
            } else {
                $blockData['override_percentage'] = null;
            }
        } else {
            $blockData['override_percentage'] = null;
        }

        // Process heading field
        if (array_key_exists('heading', $data)) {
            $blockData['heading'] = trim($data['heading']) ?: null;
        } else {
            $blockData['heading'] = null;
        }

        // Process display mode (prefer new key, fallback to legacy 'template')
        $displayMode = null;
        if (array_key_exists('display_mode', $data)) {
            $displayMode = trim((string)$data['display_mode']) ?: null;
        } elseif (array_key_exists('template', $data)) {
            $displayMode = trim((string)$data['template']) ?: null; // backward compat
        }
        $blockData['display_mode'] = $displayMode; // canonical
        // Keep legacy key for backward compatibility in stored data (optional)
        $blockData['template'] = $displayMode;

        return $blockData;
    }
}
