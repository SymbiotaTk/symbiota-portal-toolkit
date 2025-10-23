<?php

namespace Symbiota\Helpers\Core;

use Symbiota\Helpers\Core\ModuleType;
use Symbiota\Helpers\Core\TemplateFormat;
use Symbiota\Helpers\Core\Environment;
use Symbiota\Helpers\Ext\SymbAuth;

/**
 * Self-Discovering Router
 *
 * Routes requests to models based on automatic discovery,
 * eliminating the need for manual component registration.
 */
class DiscoveringRouter
{
    private ModelDiscovery $discovery;
    private OutputHandler $outputHandler;
    private array $config;
    private ?Configuration $configObject;
    private bool $treatAsHttp;

    public function __construct(?ModelDiscovery $discovery = null, ?OutputHandler $outputHandler = null, array $config = [], ?Configuration $configObject = null, bool $treatAsHttp = false)
    {
        $this->configObject = $configObject;
        $this->discovery = $discovery ?? new ModelDiscovery([], $configObject);
        $this->outputHandler = $outputHandler ?? new OutputHandler(new TemplateEngine(), false);
        $this->config = $config;
        $this->treatAsHttp = $treatAsHttp;
    }

    /**
     * Determine if we should treat this as HTTP for component filtering
     */
    private function shouldTreatAsHttp(): bool
    {
        return !Environment::isCli() || $this->treatAsHttp;
    }

    /**
     * Handle incoming request
     */
    public function handleRequest(Request $request): Response
    {
        try {
            $route = $request->getRoute();

            // Handle root/dashboard requests
            if (empty($route['elements'])) {
                return $this->renderDashboard($request);
            }

            // Extract model ID from first route element
            $modelId = $route['elements'][0]['name'] ?? '';

            if (empty($modelId)) {
                return new Response([
                    'error' => 'No model specified',
                    'type' => 'error',
                    'code' => 400
                ], 400);
            }

            // Handle global help request
            if ($modelId === 'help') {
                return $this->renderGlobalHelp();
            }

            // Route to specific model
            return $this->routeToModel($modelId, $request);

        } catch (\Exception $e) {
            return new Response([
                'error' => $e->getMessage(),
                'type' => 'error',
                'code' => 500
            ], 500);
        }
    }

    /**
     * Route request to specific model
     */
    private function routeToModel(string $modelId, Request $request): Response
    {
        // Check if model exists and is enabled
        if (!$this->discovery->modelExists($modelId)) {
            return new Response([
                'error' => "Model '{$modelId}' not found",
                'type' => 'error',
                'code' => 404
            ], 404);
        }

        if (!$this->discovery->isModelEnabled($modelId)) {
            return new Response([
                'error' => "Model '{$modelId}' is not available",
                'type' => 'error',
                'code' => 403
            ], 403);
        }

        // For HTTP requests, check if component is disabled for HTTP access
        if ($this->shouldTreatAsHttp() && !$this->discovery->isModelAccessibleViaHttp($modelId)) {
            return new Response([
                'error' => "Model '{$modelId}' is not available via HTTP",
                'type' => 'error',
                'code' => 403
            ], 403);
        }

        // Handle model-specific help
        if ($request->getInput('help') || $request->getInput('h')) {
            return $this->renderModelHelp($modelId);
        }

        // Create model instance with enhanced config including base URL and environment context
        $modelConfig = array_merge($this->config, [
            'base_url' => $request->getBasePath(),
            'app_url_prefix' => $request->getAppUrlPrefix(),
            'environment' => Environment::detect(),
            'is_cli' => Environment::isCli(),
            'is_http' => Environment::isHttp()
        ]);

        $model = $this->discovery->createModelInstance($modelId, $modelConfig);

        if (!$model) {
            return new Response([
                'error' => "Failed to create model instance for '{$modelId}'",
                'type' => 'error',
                'code' => 500
            ], 500);
        }

        return $this->executeModelAction($model, $request);
    }

    /**
     * Execute model action
     */
    private function executeModelAction(DiscoverableModel $model, Request $request): Response
    {
        $route = $request->getRoute();
        $params = $request->getInput();



        // Validate parameters against model help
        $validationErrors = $model->validateParameters($params);
        if (!empty($validationErrors)) {
            return new Response([
                'error' => 'Parameter validation failed: ' . implode(', ', $validationErrors),
                'type' => 'error',
                'code' => 400
            ], 400);
        }

        // Extract action from route elements
        $action = $route['elements'][1]['name'] ?? 'index';

        // Execute action on model
        try {
            $result = $this->callModelAction($model, $action, $route, $params);

            if (is_array($result)) {
                // Determine response format - URL format takes precedence
                $requestedFormat = $request->getFormat();
                $responseType = $requestedFormat ?? $result['type'] ?? 'json';



                switch ($responseType) {
                    case 'html':
                        // Full HTML page/document - wrap in layout with navigation
                        $content = $result['content'] ?? '';

                        // If content is already a full page, return as-is
                        if (strpos($content, '<!DOCTYPE html>') !== false) {
                            return Response::html($content);
                        }

                        // Otherwise, wrap in layout with navigation
                        $templateEngine = $this->outputHandler->getTemplateEngine();
                        $enabledComponents = $this->shouldTreatAsHttp()
                            ? $this->discovery->getDisplayableComponentsForHttp()
                            : $this->discovery->getDisplayableComponents();
                        $modelId = $model::getModelId();
                        $navInfo = $model::getNavigationInfo();

                        // Generate navigation items
                        $navigationItems = $this->generateNavigationItems($enabledComponents, $request->getAppUrlPrefix(), $modelId);

                        // Set global template variables
                        $templateEngine->setGlobalVariables([
                            'app_url_prefix' => $request->getAppUrlPrefix(),
                            'base_url' => $request->getBasePath()
                        ]);

                        // Get authentication status for template
                        $authStatus = SymbAuth::getAuthStatus();

                        // Get portal navigation configuration
                        $config = Configuration::getInstance();
                        $portalNavigationText = $config->get('app.portal_navigation_text', 'Return to portal');
                        $portalUrl = $config->get('app.portal_url', $templateEngine->getGlobalVariable('app_url_prefix', './'));

                        // Wrap in layout
                        $fullPage = $templateEngine->template('dashboard/layout', TemplateFormat::HTML, [
                            'page_title' => $navInfo['display_name'] . ' - ' . $templateEngine->getGlobalVariable('app_name', 'Symbiota Portal Helpers'),
                            'content' => $content,
                            $modelId . '_active' => 'active',
                            'navigation_items' => $navigationItems,
                            'breadcrumb_dashboard_item' => '<li class="breadcrumb-item"><a href="' . $templateEngine->getGlobalVariable('app_url_prefix', './') . '"><i class="fas fa-home"></i> Dashboard</a></li>',
                            'breadcrumb_items' => '<li class="breadcrumb-item active">' . $navInfo['display_name'] . '</li>',
                            'php_version' => PHP_VERSION,
                            'auth_greeting' => $authStatus['greeting'],
                            'auth_greeting_html' => $authStatus['authenticated'] ?
                                '<li class="nav-item"><span class="nav-link user-greeting">' . htmlspecialchars($authStatus['greeting']) . '</span></li>' : '',
                            'auth_status' => $authStatus,
                            'testing_mode_class' => $authStatus['is_testing'] ? 'testing-mode' : '',
                            'portal_navigation_text' => $portalNavigationText,
                            'portal_url' => $portalUrl,
                            'maintenance_banner' => MaintenanceBanner::getHtml()
                        ]);

                        return Response::html($fullPage);

                    case 'htmx':
                    case 'html-partial':
                    case 'html_fragment':  // Legacy support
                        // HTML fragments/partials for HTMX or other partial requests
                        $response = Response::html($result['content'] ?? '', 200);
                        $response->setHeader('Content-Type', 'text/html; charset=utf-8');

                        // Add any custom headers from the result
                        if (isset($result['headers']) && is_array($result['headers'])) {
                            foreach ($result['headers'] as $name => $value) {
                                $response->setHeader($name, $value);
                            }
                        }

                        return $response;

                    case 'file':
                        // Static file content
                        $content = $result['content'] ?? '';
                        $mimeType = $result['mime_type'] ?? 'application/octet-stream';
                        $statusCode = $result['code'] ?? 200;
                        $headers = $result['headers'] ?? [];

                        // Create file response
                        return Response::file($content, $mimeType, $statusCode, $headers);

                    case 'download':
                        // File download from disk
                        $filePath = $result['file_path'] ?? '';
                        $filename = $result['filename'] ?? '';
                        $mimeType = $result['content_type'] ?? 'application/octet-stream';

                        // Create download response
                        return Response::download($filePath, $filename, $mimeType);

                    case 'error':
                        // Error response - preserve the type for CLI handling
                        $statusCode = $result['status_code'] ?? $result['code'] ?? 500;
                        return new Response([
                            'error' => $result['message'] ?? $result['error'] ?? 'Unknown error',
                            'type' => 'error',
                            'code' => $statusCode
                        ], $statusCode);

                    case 'cli':
                    case 'success':
                        // CLI or success response - preserve original structure for OutputHandler
                        // Add format parameter to result if specified
                        $format = $params['format'] ?? $request->get('format');
                        if ($format) {
                            $result['format'] = $format;
                        }
                        return Response::json($result);

                    case 'json':
                    default:
                        // Add format parameter to result if specified
                        $format = $params['format'] ?? $request->get('format');
                        if ($format) {
                            $result['format'] = $format;
                        }
                        return Response::json($result);
                }
            } else {
                return Response::html($result);
            }
        } catch (\Exception $e) {
            return new Response([
                'error' => $e->getMessage(),
                'type' => 'error',
                'code' => 500
            ], 500);
        }
    }

    /**
     * Call action method on model
     */
    private function callModelAction(DiscoverableModel $model, string $action, array $route, array $params)
    {


        // Add format to params if available
        if (isset($route['format']) && $route['format']) {
            $params['format'] = $route['format'];
        }

        // Try to find appropriate method
        $methodName = 'handle' . ucfirst($action);

        if (method_exists($model, $methodName)) {
            return $model->$methodName($route, $params);
        }



        // Fallback to generic action handler
        if (method_exists($model, 'handleAction')) {
            // For StaticContentModel, we need to pass the full route structure
            // because it needs access to all elements to determine file type and path
            if ($model instanceof \Symbiota\Helpers\Models\StaticContentModel) {
                return $model->handleAction($action, $route, $params);
            }

            // For other models, extract route elements (skip the model name AND action)
            $routeElements = array_slice($route['elements'] ?? [], 2);
            $routeElementNames = array_map(function($element) {
                return $element['name'] ?? '';
            }, $routeElements);

            return $model->handleAction($action, $routeElementNames, $params);
        }

        // Default behavior for common actions
        switch ($action) {
            case 'index':
            case 'help':
                return $model::generateCliHelp();
            default:
                throw new \RuntimeException("Unknown action '{$action}' for model '{$model::getModelId()}'");
        }
    }

    /**
     * Get the current script name dynamically
     */
    private function getScriptName(): string
    {
        // Get the script filename from $_SERVER
        $scriptPath = $_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? 'helpers.php';

        // Extract just the filename (not the full path)
        $scriptName = basename($scriptPath);

        // Fallback to helpers.php if we can't determine the script name
        return $scriptName ?: 'helpers.php';
    }

    /**
     * Render dashboard with discovered models
     */
    private function renderDashboard(Request $request): Response
    {
        // Get displayable components - filter for HTTP if treating as HTTP
        $components = $this->shouldTreatAsHttp()
            ? $this->discovery->getDisplayableComponentsForHttp()
            : $this->discovery->getDisplayableComponents();
        $services = $this->discovery->getServices();
        $libraries = $this->discovery->getLibraries();

        // Extract just the enabled component IDs for the UI
        $enabledComponents = array_filter($components, fn($c) => $c['enabled']);
        $componentIds = array_column($enabledComponents, 'id');

        $dashboardData = [
            'type' => 'dashboard',
            'title' => 'Symbiota Portal Helpers v2.0',
            'components' => $enabledComponents,
            'services' => $services,
            'libraries' => $libraries,
            'total_components' => count($enabledComponents),
            'total_services' => count($services),
            'total_libraries' => count($libraries),
            'system_info' => [
                'php_version' => PHP_VERSION,
                'memory_usage' => memory_get_usage(true),
                'discovery_time' => microtime(true)
            ]
        ];

        if ($request->isJson() || $request->isAjax()) {
            return Response::json($dashboardData);
        } else {
            // Use the new layout system for HTML dashboard
            $templateEngine = $this->outputHandler->getTemplateEngine();

            // Set global template variables
            $templateEngine->setGlobalVariables([
                'app_url_prefix' => $request->getAppUrlPrefix(),
                'base_url' => $request->getBasePath()
            ]);

            // Generate component cards HTML
            $componentCards = $this->generateComponentCards($enabledComponents, $request->getAppUrlPrefix());

            // Generate quick actions HTML
            $quickActionsHtml = $this->generateQuickActionsHtml($enabledComponents, $request->getAppUrlPrefix());

            // Get the dashboard content
            $dashboardContent = $templateEngine->template('dashboard/content', TemplateFormat::HTML, array_merge($dashboardData, [
                'php_version' => PHP_VERSION,
                'component_cards' => $componentCards,
                'script_name' => $this->getScriptName(),
                'quick_actions_html' => $quickActionsHtml,
                'app_url_prefix' => $request->getAppUrlPrefix()
            ]));

            // Generate navigation items
            $navigationItems = $this->generateNavigationItems($enabledComponents, $request->getAppUrlPrefix(), '');

            // Get authentication status
            $authStatus = SymbAuth::getAuthStatus();

            // Get portal navigation configuration
            $config = Configuration::getInstance();
            $portalNavigationText = $config->get('app.portal_navigation_text', 'Return to portal');
            $portalUrl = $config->get('app.portal_url', $request->getAppUrlPrefix());

            // Wrap in layout
            $fullPage = $templateEngine->template('dashboard/layout', TemplateFormat::HTML, [
                'page_title' => 'Dashboard - ' . $templateEngine->getGlobalVariable('app_name', 'Symbiota Portal Helpers'),
                'content' => $dashboardContent,
                'dashboard_active' => 'active',
                'navigation_items' => $navigationItems,
                'breadcrumb_dashboard_item' => '<li class="breadcrumb-item active"><i class="fas fa-home"></i> Dashboard</li>',
                'breadcrumb_items' => '',
                'php_version' => PHP_VERSION,
                'auth_greeting' => $authStatus['greeting'],
                'auth_greeting_html' => $authStatus['authenticated'] ?
                    '<li class="nav-item"><span class="nav-link user-greeting">' . htmlspecialchars($authStatus['greeting']) . '</span></li>' : '',
                'auth_status' => $authStatus,
                'testing_mode_class' => $authStatus['is_testing'] ? 'testing-mode' : '',
                'portal_navigation_text' => $portalNavigationText,
                'portal_url' => $portalUrl,
                'maintenance_banner' => MaintenanceBanner::getHtml()
            ]);

            return Response::html($fullPage);
        }
    }

    /**
     * Render global help
     */
    private function renderGlobalHelp(): Response
    {
        $helpText = $this->discovery->generateGlobalHelp();

        return Response::html($helpText, 200, [
            'Content-Type' => 'text/plain; charset=utf-8'
        ]);
    }

    /**
     * Render model-specific help
     */
    private function renderModelHelp(string $modelId): Response
    {
        $model = $this->discovery->getModel($modelId);

        if (!$model) {
            return new Response([
                'error' => "Model '{$modelId}' not found",
                'type' => 'error',
                'code' => 404
            ], 404);
        }

        $className = $model['class'];
        $helpText = $className::generateCliHelp();

        // Return structured help response for proper CLI formatting
        return new Response([
            'type' => 'help',
            'content' => $helpText,
            'model' => $modelId
        ], 200, [
            'Content-Type' => 'text/plain; charset=utf-8'
        ]);
    }

    /**
     * Get discovery service
     */
    public function getDiscovery(): ModelDiscovery
    {
        return $this->discovery;
    }

    /**
     * Get available models
     */
    public function getAvailableModels(): array
    {
        return $this->discovery->getEnabledModels();
    }

    /**
     * Check if model exists
     */
    public function modelExists(string $modelId): bool
    {
        return $this->discovery->modelExists($modelId);
    }

    /**
     * Check if model is enabled
     */
    public function isModelEnabled(string $modelId): bool
    {
        return $this->discovery->isModelEnabled($modelId);
    }

    /**
     * Generate component cards HTML for dashboard
     */
    private function generateComponentCards(array $components, string $appUrlPrefix): string
    {
        $cards = [];
        $colors = ['primary', 'secondary', 'success', 'info', 'warning', 'danger'];
        $icons = [
            'genbank' => 'fas fa-dna',
            'taxonomy-report' => 'fas fa-sitemap',
            'images' => 'fas fa-images',
            'backup' => 'fas fa-shield-alt',
            'upload' => 'fas fa-cloud-upload-alt',
            'css|js|assets' => 'fas fa-file-code'
        ];

        foreach ($components as $index => $component) {
            $colorClass = $colors[(int)$index % count($colors)];
            $iconClass = $icons[$component['id']] ?? 'fas fa-cog';
            $title = $this->getComponentTitle($component['id']);

            $cards[] = sprintf(
                '<div class="col-md-6">
                    <div class="card h-100 border-%s">
                        <div class="card-body text-center">
                            <i class="%s fa-3x text-%s mb-3"></i>
                            <h5 class="card-title">%s</h5>
                            <p class="card-text">%s</p>
                            <a href="%s%s" class="btn btn-%s">
                                <i class="fas fa-arrow-right me-1"></i>
                                Open %s
                            </a>
                        </div>
                    </div>
                </div>',
                $colorClass,
                $iconClass,
                $colorClass,
                htmlspecialchars($title),
                htmlspecialchars($component['description']),
                $appUrlPrefix,
                $component['id'],
                $colorClass,
                htmlspecialchars($title)
            );
        }

        return implode("\n                            ", $cards);
    }

    /**
     * Generate quick actions HTML for dashboard
     */
    private function generateQuickActionsHtml(array $components, string $appUrlPrefix): string
    {
        $actions = [];

        // Check if components are enabled
        $componentIds = array_column($components, 'id');
        $isGenBankEnabled = in_array('genbank', $componentIds);
        $isTaxonomyReportEnabled = in_array('taxonomy-report', $componentIds);

        // GenBank actions (always show if genbank is enabled)
        if ($isGenBankEnabled) {
            $actions[] = '<div class="col-md-3">
                <a href="' . $appUrlPrefix . 'genbank/collections" class="btn btn-outline-primary w-100">
                    <i class="fas fa-list me-1"></i>
                    View Collections
                </a>
            </div>';

            $actions[] = '<div class="col-md-3">
                <a href="' . $appUrlPrefix . 'genbank/form/catalog" class="btn btn-outline-primary w-100">
                    <i class="fas fa-search me-1"></i>
                    Search Catalog
                </a>
            </div>';

            $actions[] = '<div class="col-md-3">
                <a href="' . $appUrlPrefix . 'genbank/form/occid" class="btn btn-outline-primary w-100">
                    <i class="fas fa-hashtag me-1"></i>
                    Search by ID
                </a>
            </div>';
        }

        // Taxonomy Report action (only show if enabled)
        if ($isTaxonomyReportEnabled) {
            $actions[] = '<div class="col-md-3">
                <a href="' . $appUrlPrefix . 'taxonomy-report" class="btn btn-outline-secondary w-100">
                    <i class="fas fa-chart-bar me-1"></i>
                    Generate Report
                </a>
            </div>';
        }

        return implode('', $actions);
    }

    /**
     * Generate navigation items HTML for layout
     */
    private function generateNavigationItems(array $components, string $appUrlPrefix, string $activeModule): string
    {
        $templateEngine = $this->outputHandler->getTemplateEngine();
        $navigationItems = [];

        foreach ($components as $component) {
            $modelClass = $component['class'];
            $navInfo = $modelClass::getNavigationInfo();

            // Skip if not displayable
            if ($navInfo['module_type'] !== ModuleType::COMPONENT) {
                continue;
            }

            $activeClass = ($navInfo['id'] === $activeModule) ? 'active' : '';

            $navigationItems[] = $templateEngine->template('dashboard/navigation_item', TemplateFormat::HTML, [
                'app_url_prefix' => $appUrlPrefix,
                'module_id' => $navInfo['id'],
                'display_name' => $navInfo['display_name'],
                'icon_class' => $navInfo['icon_class'],
                'active_class' => $activeClass
            ]);
        }

        return implode('', $navigationItems);
    }

    /**
     * Get user-friendly component title
     */
    private function getComponentTitle(string $componentId): string
    {
        $titles = [
            'genbank' => 'GenBank Tool',
            'taxonomy-report' => 'Taxonomy Report',
            'images' => 'Image Gallery',
            'backup' => 'Backup Manager',
            'upload' => 'File Upload',
            'css|js|assets' => 'Static Content'
        ];

        return $titles[$componentId] ?? ucfirst(str_replace('-', ' ', $componentId));
    }
}
