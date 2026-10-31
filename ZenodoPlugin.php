<?php

namespace APP\plugins\generic\zenodo;

use APP\core\Application;
use APP\plugins\generic\zenodo\controllers\grid\ZenodoSubmissionsListGridHandler;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;

class ZenodoPlugin extends GenericPlugin
{
    protected ?ZenodoExportPlugin $exportPlugin = null;

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register(
            $category,
            $path,
            $mainContextId
        );

        if (!$success) {
            return false;
        }

        if (Application::isUnderMaintenance()) {
            return true;
        }

        // Register export plugin using OJS 3.4 architecture.
        $this->setExportPlugin();

        if ($this->getEnabled($mainContextId)) {
            Hook::add(
                'LoadComponentHandler',
                [$this, 'setupGridHandler']
            );
        }

        return true;
    }

    public function setupGridHandler(
        string $hookName,
        array $params
    ): bool {
        $component = &$params[0];
        $componentInstance = &$params[2];

        $handlerClass = match ($component) {
            'plugins.generic.zenodo.controllers.grid.ZenodoSubmissionsListGridHandler'
                => ZenodoSubmissionsListGridHandler::class,
            default => null,
        };

        if (!$handlerClass) {
            return false;
        }

        $componentInstance = new $handlerClass();

        return true;
    }

    public function getDisplayName(): string
    {
        return __('plugins.generic.zenodo.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.zenodo.description');
    }

    protected function setExportPlugin(): void
    {
        PluginRegistry::register(
            'importexport',
            new ZenodoExportPlugin(),
            $this->getPluginPath()
        );

        $this->exportPlugin = PluginRegistry::getPlugin(
            'importexport',
            'ZenodoExportPlugin'
        );
    }

    public function getContextSpecificPluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    public function getInstallSitePluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }
}

