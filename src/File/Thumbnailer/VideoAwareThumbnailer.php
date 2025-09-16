<?php
namespace DerivativeMedia\File\Thumbnailer;

use Omeka\File\Thumbnailer\ImageMagick;
use Omeka\File\TempFileFactory;
use Omeka\Stdlib\Cli;

/**
 * Video-Aware Thumbnailer
 * 
 * This thumbnailer extends the default ImageMagick thumbnailer to handle
 * video files using FFmpeg for thumbnail generation at specified percentages
 */
class VideoAwareThumbnailer extends ImageMagick
{
    /**
     * @var array Video MIME types that should use FFmpeg
     */
    protected $videoTypes = [
        'video/mp4',
        'video/mpeg',
        'video/ogg', 
        'video/quicktime',
        'video/webm',
        'video/x-ms-asf',
        'video/x-msvideo',
        'video/x-ms-wmv',
        'video/avi',
        'video/mov',
        'video/wmv',
        'video/mkv',
        'video/m4v'
    ];

    /**
     * @var string Path to FFmpeg binary
     */
    protected $ffmpegPath;

    /**
     * @var string Path to FFprobe binary  
     */
    protected $ffprobePath;

    /**
     * @var int Default thumbnail position percentage
     */
    protected $defaultPercentage = 25;

    public function __construct(Cli $cli, TempFileFactory $tempFileFactory, array $options = [])
    {
        parent::__construct($cli, $tempFileFactory, $options);

        // Set FFmpeg paths
        $this->ffmpegPath = $options['ffmpeg_path'] ?? '/usr/bin/ffmpeg';
        $this->ffprobePath = $options['ffprobe_path'] ?? '/usr/bin/ffprobe';
        $this->defaultPercentage = $options['default_percentage'] ?? 25;

        // Debug logging
        error_log('VideoAwareThumbnailer: Constructed with FFmpeg: ' . $this->ffmpegPath . ', percentage: ' . $this->defaultPercentage);
    }

    /**
     * Create thumbnail using FFmpeg for videos, ImageMagick for everything else
     */
    public function create($strategy, $constraint, array $options = [])
    {
        $mediaType = $this->sourceFile->getMediaType();
        $sourcePath = $this->sourceFile->getTempPath();

        error_log('VideoAwareThumbnailer: create() called for media type: ' . $mediaType . ', strategy: ' . $strategy . ', constraint: ' . $constraint);
        error_log('VideoAwareThumbnailer: source path: ' . $sourcePath);
        error_log('VideoAwareThumbnailer: file exists: ' . (file_exists($sourcePath) ? 'YES' : 'NO'));
        if (file_exists($sourcePath)) {
            error_log('VideoAwareThumbnailer: file size: ' . filesize($sourcePath) . ' bytes');
        }

        // CRITICAL FIX: Check if this is already a processed thumbnail
        // If the source file has a .jpg extension, it's likely a thumbnail that needs resizing
        $hasJpgExtension = pathinfo($sourcePath, PATHINFO_EXTENSION) === 'jpg';

        if ($hasJpgExtension) {
            error_log('VideoAwareThumbnailer: Source has .jpg extension, treating as processed thumbnail');
            error_log('VideoAwareThumbnailer: File exists: ' . (file_exists($sourcePath) ? 'YES' : 'NO'));

            if (!file_exists($sourcePath)) {
                error_log('VideoAwareThumbnailer: CRITICAL - Source file does not exist: ' . $sourcePath);
                throw new \Exception('Source thumbnail file does not exist: ' . $sourcePath);
            }

            // For JPEG thumbnails, create a simple resize using ImageMagick directly
            // Bypass Omeka's ImageMagick thumbnailer which adds [0] frame syntax
            error_log('VideoAwareThumbnailer: Creating direct ImageMagick resize for JPEG thumbnail');
            return $this->createDirectImageResize($sourcePath, $strategy, $constraint, $options);
        }

        // Use FFmpeg for video files (original video sources)
        if (in_array($mediaType, $this->videoTypes)) {
            error_log('VideoAwareThumbnailer: Using FFmpeg for video file: ' . $mediaType);
            return $this->createVideoThumbnail($strategy, $constraint, $options);
        }

        // For non-video files, ensure the source file has proper extension for ImageMagick
        error_log('VideoAwareThumbnailer: Using ImageMagick for non-video file: ' . $mediaType);

        // Check if the source file has an extension
        $hasExtension = pathinfo($sourcePath, PATHINFO_EXTENSION) !== '';
        error_log('VideoAwareThumbnailer: source file has extension: ' . ($hasExtension ? 'YES' : 'NO'));

        if (!$hasExtension) {
            error_log('VideoAwareThumbnailer: Adding extension for ImageMagick compatibility');

            // Add appropriate extension based on media type
            $extension = $this->getExtensionForMediaType($mediaType);
            $sourcePathWithExtension = $sourcePath . '.' . $extension;

            error_log('VideoAwareThumbnailer: Copying to path with extension: ' . $sourcePathWithExtension);

            // Copy the file to include extension
            if (copy($sourcePath, $sourcePathWithExtension)) {
                error_log('VideoAwareThumbnailer: Successfully copied file with extension');

                // Create a new temp file with extension
                $tempFileWithExtension = $this->tempFileFactory->build();
                $tempFileWithExtension->setTempPath($sourcePathWithExtension);
                $tempFileWithExtension->setSourceName($this->sourceFile->getSourceName());
                $tempFileWithExtension->setStorageId($this->sourceFile->getStorageId());

                // Temporarily replace source file
                $originalSourceFile = $this->sourceFile;
                $this->sourceFile = $tempFileWithExtension;

                try {
                    error_log('VideoAwareThumbnailer: Calling parent ImageMagick with extension');
                    $result = parent::create($strategy, $constraint, $options);

                    // Clean up
                    unlink($sourcePathWithExtension);
                    $this->sourceFile = $originalSourceFile;

                    error_log('VideoAwareThumbnailer: Parent ImageMagick succeeded');
                    return $result;
                } catch (\Exception $e) {
                    error_log('VideoAwareThumbnailer: Parent ImageMagick failed: ' . $e->getMessage());
                    // Clean up and restore
                    unlink($sourcePathWithExtension);
                    $this->sourceFile = $originalSourceFile;
                    throw $e;
                }
            } else {
                error_log('VideoAwareThumbnailer: Failed to copy file with extension');
            }
        }

        error_log('VideoAwareThumbnailer: Calling parent ImageMagick directly');
        return parent::create($strategy, $constraint, $options);
    }

    /**
     * Create video thumbnail using FFmpeg
     */
    protected function createVideoThumbnail($strategy, $constraint, array $options = [])
    {
        $sourcePath = $this->sourceFile->getTempPath();
        $tempFile = $this->tempFileFactory->build();
        $tempPath = $tempFile->getTempPath();

        // CRITICAL FIX: Add .jpg extension so FFmpeg knows the output format
        // But keep the original temp path for Omeka's pipeline
        $tempPathWithExtension = $tempPath . '.jpg';

        // Get video duration
        $duration = $this->getVideoDuration($sourcePath);
        if ($duration <= 0) {
            // Fallback to 10 seconds if duration can't be determined
            $position = 10;
        } else {
            // Calculate position based on percentage
            $percentage = $options['percentage'] ?? $this->defaultPercentage;
            $position = ($duration * $percentage) / 100;
        }

        // Build FFmpeg command based on strategy
        $command = $this->buildFFmpegCommand($sourcePath, $tempPathWithExtension, $position, $strategy, $constraint, $options);
        
        // Execute FFmpeg command directly (bypass Omeka CLI wrapper)
        error_log('VideoAwareThumbnailer: FFmpeg command: ' . $command);

        $output = [];
        $result = 0;
        exec($command . ' 2>&1', $output, $result);

        $outputString = implode("\n", $output);
        error_log('VideoAwareThumbnailer: FFmpeg result: ' . $result);
        error_log('VideoAwareThumbnailer: FFmpeg output: ' . $outputString);

        if (0 !== $result) {
            throw new \Exception(sprintf('FFmpeg command failed (exit code %d): %s. Output: %s', $result, $command, $outputString));
        }

        if (!file_exists($tempPathWithExtension) || 0 === filesize($tempPathWithExtension)) {
            throw new \Exception(sprintf('FFmpeg failed to create thumbnail. File exists: %s, Size: %d. Output: %s',
                file_exists($tempPathWithExtension) ? 'yes' : 'no',
                file_exists($tempPathWithExtension) ? filesize($tempPathWithExtension) : 0,
                $outputString
            ));
        }

        $thumbnailSize = filesize($tempPathWithExtension);
        error_log('VideoAwareThumbnailer: FFmpeg created thumbnail: ' . $tempPathWithExtension . ' (size: ' . $thumbnailSize . ' bytes)');

        // ENHANCED: Validate the generated JPEG file
        if ($thumbnailSize < 100) {
            throw new \Exception('FFmpeg generated thumbnail is too small (likely corrupted): ' . $thumbnailSize . ' bytes');
        }

        // Validate JPEG header
        $handle = fopen($tempPathWithExtension, 'rb');
        if ($handle) {
            $header = fread($handle, 4);
            fclose($handle);

            if (strlen($header) >= 3 && ord($header[0]) === 0xFF && ord($header[1]) === 0xD8 && ord($header[2]) === 0xFF) {
                error_log('VideoAwareThumbnailer: Generated thumbnail has valid JPEG header');
            } else {
                error_log('VideoAwareThumbnailer: WARNING - Generated thumbnail has invalid JPEG header: ' . bin2hex($header));
                throw new \Exception('FFmpeg generated invalid JPEG file (bad header): ' . bin2hex($header));
            }
        }

        // Test with getimagesize
        $imageInfo = @getimagesize($tempPathWithExtension);
        if ($imageInfo === false) {
            error_log('VideoAwareThumbnailer: WARNING - Generated thumbnail failed getimagesize validation');
            throw new \Exception('FFmpeg generated invalid image file (failed getimagesize)');
        } else {
            error_log('VideoAwareThumbnailer: Generated thumbnail validated - Width: ' . $imageInfo[0] . ', Height: ' . $imageInfo[1]);
        }

        // CRITICAL FIX: Copy the thumbnail to the expected path WITHOUT extension
        // Omeka's thumbnail system expects files without extensions in temp paths
        if (!copy($tempPathWithExtension, $tempPath)) {
            throw new \Exception('Failed to copy generated thumbnail to expected location');
        }

        error_log('VideoAwareThumbnailer: Copied to final path: ' . $tempPath . ' (size: ' . filesize($tempPath) . ' bytes)');

        // Clean up the temporary file with extension
        unlink($tempPathWithExtension);

        error_log('VideoAwareThumbnailer: Final thumbnail ready at: ' . $tempPath . ' (size: ' . filesize($tempPath) . ' bytes)');

        // Return the temp path WITHOUT extension - this is what Omeka expects
        // The parent ImageMagick thumbnailer will handle any further processing
        return $tempPath;
    }

    /**
     * Get video duration using FFprobe
     */
    protected function getVideoDuration($videoPath)
    {
        $command = sprintf(
            '%s -v quiet -show_entries format=duration -of csv="p=0" %s 2>/dev/null',
            escapeshellarg($this->ffprobePath),
            escapeshellarg($videoPath)
        );

        $output = shell_exec($command);
        return $output ? (float) trim($output) : 0;
    }

    /**
     * Build FFmpeg command for thumbnail creation
     */
    protected function buildFFmpegCommand($sourcePath, $outputPath, $position, $strategy, $constraint, $options)
    {
        // Build command as array for proper escaping
        $args = [
            escapeshellarg($this->ffmpegPath),
            '-y', // Overwrite output file
            '-i', escapeshellarg($sourcePath),
            '-ss', escapeshellarg(sprintf('%.2f', $position)), // Format position to 2 decimal places
            '-vframes', '1'
        ];

        // Apply video filters based on strategy
        if ($strategy === 'square') {
            // Square thumbnail with cropping
            $size = $constraint; // For square, constraint is the dimension
            $args[] = '-vf';
            $args[] = escapeshellarg("scale={$size}:{$size}:force_original_aspect_ratio=increase,crop={$size}:{$size}");
        } else {
            // Default strategy - scale to fit within constraint
            $args[] = '-vf';
            $args[] = escapeshellarg("scale={$constraint}:-1");
        }

        // CRITICAL FIX: Add format specification like the working manual command
        $args[] = '-f';
        $args[] = 'image2';

        // Quality settings (use same as manual command)
        $args[] = '-q:v';
        $args[] = '2'; // High quality

        // Output path
        $args[] = escapeshellarg($outputPath);

        return implode(' ', $args);
    }

    /**
     * Check if this thumbnailer can handle the given file
     */
    public function canThumbnail()
    {
        $mediaType = $this->sourceFile->getMediaType();
        
        // Can handle videos with FFmpeg or anything ImageMagick can handle
        if (in_array($mediaType, $this->videoTypes)) {
            return $this->checkFFmpegAvailable();
        }
        
        return parent::canThumbnail();
    }

    /**
     * Check if FFmpeg is available
     */
    protected function checkFFmpegAvailable()
    {
        return file_exists($this->ffmpegPath) && is_executable($this->ffmpegPath);
    }

    /**
     * Get appropriate file extension for media type
     */
    protected function getExtensionForMediaType($mediaType)
    {
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ];

        return $extensions[$mediaType] ?? 'jpg'; // Default to jpg
    }

    /**
     * Create direct ImageMagick resize for JPEG thumbnails
     * This bypasses Omeka's ImageMagick thumbnailer which adds problematic [0] frame syntax
     */
    protected function createDirectImageResize($sourcePath, $strategy, $constraint, array $options = [])
    {
        $tempFile = $this->tempFileFactory->build();
        $tempPath = $tempFile->getTempPath();

        // CRITICAL FIX: Ensure output file has proper extension
        if (!pathinfo($tempPath, PATHINFO_EXTENSION)) {
            $tempPath .= '.jpg';
        }

        error_log('VideoAwareThumbnailer: Direct resize from ' . $sourcePath . ' to ' . $tempPath);

        // COMPREHENSIVE SOURCE FILE VALIDATION
        if (!file_exists($sourcePath)) {
            throw new \Exception('Source file does not exist: ' . $sourcePath);
        }

        $sourceSize = filesize($sourcePath);
        error_log('VideoAwareThumbnailer: Source file size: ' . $sourceSize . ' bytes');

        if ($sourceSize === 0) {
            throw new \Exception('Source file is empty: ' . $sourcePath);
        }

        if ($sourceSize < 100) {
            throw new \Exception('Source file too small (likely corrupted): ' . $sourceSize . ' bytes');
        }

        // ENHANCED: Validate JPEG file with multiple methods
        $imageInfo = @getimagesize($sourcePath);
        if ($imageInfo === false) {
            error_log('VideoAwareThumbnailer: getimagesize() failed for: ' . $sourcePath);

            // Try alternative validation with ImageMagick identify
            $identifyOutput = [];
            $identifyResult = 0;
            exec('identify ' . escapeshellarg($sourcePath) . ' 2>&1', $identifyOutput, $identifyResult);

            if ($identifyResult !== 0) {
                $identifyError = implode("\n", $identifyOutput);
                error_log('VideoAwareThumbnailer: ImageMagick identify failed: ' . $identifyError);
                throw new \Exception('Source file is not a valid image (failed both getimagesize and identify): ' . $sourcePath . '. Identify error: ' . $identifyError);
            } else {
                error_log('VideoAwareThumbnailer: ImageMagick identify succeeded: ' . implode(", ", $identifyOutput));
            }
        } else {
            error_log('VideoAwareThumbnailer: Source image info - Width: ' . $imageInfo[0] . ', Height: ' . $imageInfo[1] . ', Type: ' . $imageInfo[2] . ', MIME: ' . $imageInfo['mime']);
        }

        // ENHANCED: Check file permissions
        if (!is_readable($sourcePath)) {
            throw new \Exception('Source file is not readable: ' . $sourcePath);
        }

        // ENHANCED: Validate JPEG header
        $handle = fopen($sourcePath, 'rb');
        if ($handle) {
            $header = fread($handle, 4);
            fclose($handle);

            // Check for JPEG magic bytes (FF D8 FF)
            if (strlen($header) >= 3 && ord($header[0]) === 0xFF && ord($header[1]) === 0xD8 && ord($header[2]) === 0xFF) {
                error_log('VideoAwareThumbnailer: Valid JPEG header detected');
            } else {
                error_log('VideoAwareThumbnailer: WARNING - Invalid JPEG header: ' . bin2hex($header));
                // Continue anyway, might still be processable
            }
        }

        // FIXED: Simplified and more reliable ImageMagick commands
        if ($strategy === 'square') {
            // Use two-step approach for square thumbnails to avoid command parsing issues
            $tempResized = $tempPath . '_resized.jpg';

            // Step 1: Resize to fit
            $resizeCommand = sprintf(
                'convert %s -auto-orient -background white +repage -resize %dx%d^ %s',
                escapeshellarg($sourcePath),
                $constraint, $constraint,
                escapeshellarg($tempResized)
            );

            error_log('VideoAwareThumbnailer: Step 1 - Resize command: ' . $resizeCommand);
            $resizeOutput = [];
            exec($resizeCommand . ' 2>&1', $resizeOutput, $resizeResult);

            if ($resizeResult !== 0 || !file_exists($tempResized)) {
                error_log('VideoAwareThumbnailer: Step 1 failed: ' . implode("\n", $resizeOutput));
                throw new \Exception('Failed to resize image for square thumbnail: ' . implode("\n", $resizeOutput));
            }

            // Step 2: Crop to square
            $command = sprintf(
                'convert %s -gravity center -crop %dx%d+0+0 +repage %s',
                escapeshellarg($tempResized),
                $constraint, $constraint,
                escapeshellarg($tempPath)
            );
        } else {
            // Simplified command for regular thumbnails - remove problematic options
            $command = sprintf(
                'convert %s -auto-orient -background white +repage -resize %dx%d> %s',
                escapeshellarg($sourcePath),
                $constraint, $constraint,
                escapeshellarg($tempPath)
            );
        }

        error_log('VideoAwareThumbnailer: ImageMagick command: ' . $command);

        // Execute ImageMagick command
        $output = [];
        $result = 0;
        exec($command . ' 2>&1', $output, $result);

        $outputString = implode("\n", $output);
        error_log('VideoAwareThumbnailer: ImageMagick result: ' . $result);
        error_log('VideoAwareThumbnailer: ImageMagick output: ' . $outputString);

        // Clean up temporary resize file for square thumbnails
        if ($strategy === 'square' && isset($tempResized) && file_exists($tempResized)) {
            unlink($tempResized);
        }

        // Check if output file was created
        if (file_exists($tempPath)) {
            $outputSize = filesize($tempPath);
            error_log('VideoAwareThumbnailer: Output file created, size: ' . $outputSize . ' bytes');
        } else {
            error_log('VideoAwareThumbnailer: Output file was NOT created');
        }

        // ENHANCED ERROR HANDLING: Multiple fallback strategies
        if (0 !== $result) {
            error_log('VideoAwareThumbnailer: Primary command failed, trying multiple fallbacks...');

            // Fallback 1: Ultra-simple convert
            $fallback1Command = sprintf(
                'convert %s -resize %dx%d> %s',
                escapeshellarg($sourcePath),
                $constraint, $constraint,
                escapeshellarg($tempPath)
            );

            $fallback1Output = [];
            exec($fallback1Command . ' 2>&1', $fallback1Output, $fallback1Result);

            if ($fallback1Result === 0 && file_exists($tempPath) && filesize($tempPath) > 0) {
                error_log('VideoAwareThumbnailer: Fallback 1 (ultra-simple) succeeded');
                return $tempPath;
            }

            error_log('VideoAwareThumbnailer: Fallback 1 failed, trying fallback 2...');

            // Fallback 2: Force JPEG input/output format
            $fallback2Command = sprintf(
                'convert jpeg:%s -resize %dx%d> jpeg:%s',
                escapeshellarg($sourcePath),
                $constraint, $constraint,
                escapeshellarg($tempPath)
            );

            $fallback2Output = [];
            exec($fallback2Command . ' 2>&1', $fallback2Output, $fallback2Result);

            if ($fallback2Result === 0 && file_exists($tempPath) && filesize($tempPath) > 0) {
                error_log('VideoAwareThumbnailer: Fallback 2 (forced JPEG) succeeded');
                return $tempPath;
            }

            error_log('VideoAwareThumbnailer: Fallback 2 failed, trying fallback 3...');

            // Fallback 3: Use PHP GD as last resort
            if (extension_loaded('gd')) {
                try {
                    $sourceImage = @imagecreatefromjpeg($sourcePath);
                    if ($sourceImage !== false) {
                        $sourceWidth = imagesx($sourceImage);
                        $sourceHeight = imagesy($sourceImage);

                        // Calculate new dimensions
                        $ratio = min($constraint / $sourceWidth, $constraint / $sourceHeight);
                        $newWidth = (int)($sourceWidth * $ratio);
                        $newHeight = (int)($sourceHeight * $ratio);

                        $newImage = imagecreatetruecolor($newWidth, $newHeight);
                        imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);

                        if (imagejpeg($newImage, $tempPath, 85)) {
                            error_log('VideoAwareThumbnailer: Fallback 3 (PHP GD) succeeded');
                            imagedestroy($sourceImage);
                            imagedestroy($newImage);
                            return $tempPath;
                        }

                        imagedestroy($sourceImage);
                        imagedestroy($newImage);
                    }
                } catch (\Exception $gdException) {
                    error_log('VideoAwareThumbnailer: GD fallback failed: ' . $gdException->getMessage());
                }
            }

            // All fallbacks failed
            $fallback1OutputString = implode("\n", $fallback1Output);
            $fallback2OutputString = implode("\n", $fallback2Output);

            throw new \Exception(sprintf(
                'All thumbnail creation methods failed. Primary (exit %d): %s. Output: %s. Fallback1 (exit %d): %s. Output: %s. Fallback2 (exit %d): %s. Output: %s. GD also failed.',
                $result, $command, $outputString,
                $fallback1Result, $fallback1Command, $fallback1OutputString,
                $fallback2Result, $fallback2Command, $fallback2OutputString
            ));
        }

        if (!file_exists($tempPath) || 0 === filesize($tempPath)) {
            throw new \Exception(sprintf('ImageMagick failed to create thumbnail. File exists: %s, Size: %d. Output: %s',
                file_exists($tempPath) ? 'yes' : 'no',
                file_exists($tempPath) ? filesize($tempPath) : 0,
                $outputString
            ));
        }

        error_log('VideoAwareThumbnailer: Direct resize successful, created: ' . $tempPath . ' (size: ' . filesize($tempPath) . ' bytes)');

        return $tempPath;
    }
}
