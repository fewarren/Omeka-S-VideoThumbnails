# Technical Reference - DerivativeMedia Video Thumbnail Enhancement

## 🔧 **CRITICAL CODE FIXES IMPLEMENTED**

### **1. DebugManager Missing Constants Fix**

**File**: `src/Service/DebugManager.php`
**Lines**: 15-23

```php
class DebugManager
{
    // Existing constants
    const COMPONENT_FORM = 'FORM';
    const COMPONENT_BLOCK = 'BLOCK';
    const COMPONENT_FACTORY = 'FACTORY';
    const COMPONENT_SERVICE = 'SERVICE';
    const COMPONENT_API = 'API';
    const COMPONENT_RENDERER = 'RENDERER';
    const COMPONENT_HELPER = 'HELPER';
    
    // CRITICAL ADDITIONS - These were missing and causing fatal errors
    const COMPONENT_MODULE = 'MODULE';           // ✅ ADDED - Used in Module.php
    const COMPONENT_THUMBNAILER = 'THUMBNAILER'; // ✅ ADDED - Used in VideoThumbnailer
}
```

### **2. Service Access Fix in Controller Context**

**File**: `Module.php`
**Method**: `handleConfigForm()`
**Lines**: 246-250

```php
// ❌ BEFORE (BROKEN - getServiceLocator doesn't exist on controllers):
$serviceLocator = $controller->getServiceLocator();

// ✅ AFTER (FIXED - Proper Laminas service access):
$serviceManager = $controller->getEvent()->getApplication()->getServiceManager();
$config = $serviceManager->get('Config');
$settings = $serviceManager->get('Omeka\Settings');
$form = $serviceManager->get('FormElementManager')->get(Form\ConfigForm::class);
```

### **3. Force Regeneration Parameter Fix**

**File**: `src/Job/GenerateVideoThumbnails.php`
**Method**: Bulk processing loop
**Lines**: 107-108

```php
// ❌ BEFORE (BROKEN - Missing force parameter):
$success = $this->videoThumbnailService->generateThumbnail($mediaEntity, $percentage);

// ✅ AFTER (FIXED - Force parameter included):
$success = $this->videoThumbnailService->generateThumbnail($mediaEntity, $percentage, $forceRegenerate);
```

**Method Signature**: `VideoThumbnailService::generateThumbnail()`
```php
public function generateThumbnail(Media $media, int $percentage = null, bool $forceRegenerate = false): bool
```

## 🏗️ **SERVICE ACCESS PATTERNS BY CONTEXT**

### **Controller Context** (Module.php - handleConfigForm)
```php
$serviceManager = $controller->getEvent()->getApplication()->getServiceManager();
```

### **Renderer Context** (Module.php - getConfigForm)
```php
$serviceLocator = $renderer->getHelperPluginManager()->getServiceLocator();
```

### **Event Context** (Module.php - handleVideoThumbnailGeneration)
```php
$serviceManager = $event->getTarget()->getServiceLocator();
```

### **Bootstrap Context** (Module.php - onBootstrap, initializeDebugManager)
```php
$serviceManager = $event->getApplication()->getServiceManager();
```

## 📊 **DEBUGMANAGER COMPONENT ARCHITECTURE**

### **Component Categories and Usage**

```php
// Module-level operations (bootstrap, configuration, event handling)
DebugManager::COMPONENT_MODULE
// Usage: Module.php bootstrap, configuration form, event listeners

// File rendering operations (video/audio rendering)
DebugManager::COMPONENT_RENDERER  
// Usage: VideoRenderer.php, AudioRenderer.php

// Thumbnail generation operations
DebugManager::COMPONENT_THUMBNAILER
// Usage: VideoThumbnailer.php, VideoAwareThumbnailer.php

// Background services and jobs
DebugManager::COMPONENT_SERVICE
// Usage: VideoThumbnailService.php, GenerateVideoThumbnails.php, EventListener.php

// View helpers and URL generation
DebugManager::COMPONENT_HELPER
// Usage: CustomServerUrl.php, ViewerDetector.php

// Service factory operations
DebugManager::COMPONENT_FACTORY
// Usage: VideoThumbnailServiceFactory.php, VideoAwareThumbnailerFactory.php

// Block layout rendering
DebugManager::COMPONENT_BLOCK
// Usage: VideoThumbnail.php block layout

// Form processing and validation
DebugManager::COMPONENT_FORM
// Usage: ConfigForm.php

// API operations and interactions
DebugManager::COMPONENT_API
// Usage: API adapters, external service calls
```

### **Logging Usage Patterns**

```php
// Basic logging with component
$this->debugManager->logInfo('Message', DebugManager::COMPONENT_MODULE);

// Logging with operation ID for correlation
$operationId = 'operation-' . uniqid();
$this->debugManager->logInfo('Starting operation', DebugManager::COMPONENT_SERVICE, $operationId);
$this->debugManager->logInfo('Operation complete', DebugManager::COMPONENT_SERVICE, $operationId);

// Different log levels
$this->debugManager->logDebug('Debug information', DebugManager::COMPONENT_THUMBNAILER);
$this->debugManager->logWarning('Warning message', DebugManager::COMPONENT_RENDERER);
$this->debugManager->logError('Error occurred', DebugManager::COMPONENT_SERVICE);
```

## 🔄 **FORCE REGENERATION FLOW**

### **Complete Parameter Flow**

1. **Form Checkbox** (ConfigForm.php):
```php
'name' => 'force_regenerate_thumbnails',
'type' => Element\Checkbox::class,
```

2. **Parameter Extraction** (Module.php):
```php
'force_regenerate' => !empty($params['force_regenerate_thumbnails']),
```

3. **Job Argument** (Module.php):
```php
$jobArgs = [
    'query' => [],
    'force_regenerate' => !empty($params['force_regenerate_thumbnails']),
    'percentage' => !empty($params['video_thumbnail_percentage']) ? (int)$params['video_thumbnail_percentage'] : null,
];
```

4. **Job Processing** (GenerateVideoThumbnails.php):
```php
$forceRegenerate = $this->getArg('force_regenerate', false);
```

5. **Service Call** (GenerateVideoThumbnails.php):
```php
$success = $this->videoThumbnailService->generateThumbnail($mediaEntity, $percentage, $forceRegenerate);
```

6. **Service Logic** (VideoThumbnailService.php):
```php
if (!$forceRegenerate && $this->hasExistingThumbnails($media)) {
    $this->logger->info('Thumbnails already exist, skipping generation');
    return true;
}

if ($forceRegenerate) {
    $this->logger->info('Force regeneration enabled, will regenerate existing thumbnails');
}
```

## 🌐 **URL GENERATION ARCHITECTURE**

### **CustomServerUrl Helper Implementation**

**File**: `src/View/Helper/CustomServerUrl.php`

```php
public function __invoke($requestUri = null)
{
    // Multi-source URL resolution with fallback hierarchy:
    // 1. Config base_url
    // 2. Settings base_url  
    // 3. Request-based URL
    // 4. Relative fallback
    
    $baseUrl = $this->getConfiguredBaseUrl() 
            ?? $this->getSettingsBaseUrl() 
            ?? $this->getRequestBaseUrl() 
            ?? '';
    
    return $this->buildUrl($baseUrl, $requestUri);
}
```

### **Environment-Specific Configuration**

**Development** (`local.config.php`):
```php
'base_url' => 'http://localhost/omeka-s',
'file_store' => [
    'local' => [
        'base_uri' => 'http://localhost/omeka-s/files',
    ],
],
```

**Production** (`local.config.php`):
```php
'base_url' => 'https://production-domain.com/omeka-s',
'file_store' => [
    'local' => [
        'base_uri' => 'https://production-domain.com/omeka-s/files',
    ],
],
```

**Docker/Container** (`local.config.php`):
```php
'base_url' => getenv('OMEKA_BASE_URL') ?: 'http://localhost:8080',
'file_store' => [
    'local' => [
        'base_uri' => (getenv('OMEKA_BASE_URL') ?: 'http://localhost:8080') . '/files',
    ],
],
```

## 🔍 **DEBUGGING COMMANDS REFERENCE**

### **Component-Specific Monitoring**

```bash
# Monitor module bootstrap and configuration
tail -f /var/www/omeka-s/logs/application.log | grep "MODULE"

# Monitor thumbnail generation
tail -f /var/www/omeka-s/logs/application.log | grep "THUMBNAILER"

# Monitor background services and jobs
tail -f /var/www/omeka-s/logs/application.log | grep "SERVICE"

# Monitor URL generation and view helpers
tail -f /var/www/omeka-s/logs/application.log | grep "HELPER"

# Monitor file rendering
tail -f /var/www/omeka-s/logs/application.log | grep "RENDERER"
```

### **Force Regeneration Debugging**

```bash
# Check if force parameter is being extracted from form
grep "force_regenerate.*true" /var/www/omeka-s/logs/application.log

# Verify force parameter in job arguments
grep "Dispatching GenerateVideoThumbnails job" /var/www/omeka-s/logs/application.log

# Monitor force regeneration messages
grep "Force regeneration enabled" /var/www/omeka-s/logs/application.log

# Check service method calls with force parameter
grep "generateThumbnail.*force" /var/www/omeka-s/logs/application.log
```

### **Service Access Issue Debugging**

```bash
# Check for ServiceNotFoundException errors
grep "ServiceNotFoundException" /var/log/apache2/omeka-s_error.log

# Check for getServiceLocator plugin errors
grep "getServiceLocator.*not found" /var/log/apache2/omeka-s_error.log

# Monitor service manager access patterns
grep "getEvent()->getApplication()->getServiceManager" /var/www/omeka-s/logs/application.log
```

## 📁 **FILE MODIFICATION SUMMARY**

### **Core Files Modified**
- `Module.php`: Service access fixes, DebugManager integration
- `src/Service/DebugManager.php`: Added missing component constants
- `src/Job/GenerateVideoThumbnails.php`: Force parameter fix
- `src/Service/VideoThumbnailService.php`: Force regeneration logic
- `src/View/Helper/CustomServerUrl.php`: Environment-aware URL generation

### **Configuration Files**
- `config/module.config.php`: Service registrations
- `src/Form/ConfigForm.php`: Force regeneration checkbox

### **Documentation**
- `README.md`: Comprehensive updates
- `AUGMENT_CONVERSATION_CONTEXT.md`: Session context
- `TECHNICAL_REFERENCE.md`: Technical implementation details

## 🚀 **DEPLOYMENT VERIFICATION**

### **Post-Deployment Checks**

```bash
# Verify DebugManager constants
grep -n "COMPONENT_MODULE\|COMPONENT_THUMBNAILER" /var/www/omeka-s/modules/DerivativeMedia/src/Service/DebugManager.php

# Verify service access fix
grep -n "getEvent()->getApplication()->getServiceManager" /var/www/omeka-s/modules/DerivativeMedia/Module.php

# Verify force parameter fix
grep -n "generateThumbnail.*forceRegenerate" /var/www/omeka-s/modules/DerivativeMedia/src/Job/GenerateVideoThumbnails.php

# Check file ownership
ls -la /var/www/omeka-s/modules/DerivativeMedia/Module.php
ls -la /var/www/omeka-s/modules/DerivativeMedia/src/Service/DebugManager.php
ls -la /var/www/omeka-s/modules/DerivativeMedia/src/Job/GenerateVideoThumbnails.php
```

### **Functional Testing**

1. **Module Bootstrap**: Navigate to any Omeka S page - no fatal errors
2. **Configuration Form**: Access module configuration - no ServiceNotFoundException
3. **Force Regeneration**: Check force option and process videos - thumbnails regenerated
4. **Job Processing**: Monitor Jobs page - successful job creation and completion
5. **Logging**: Check logs for component-based debug output

**This technical reference provides all implementation details needed to understand and maintain the Video Thumbnail enhancement fixes.**
