<?php

namespace APP\plugins\generic\fileManager;

use APP\core\Application;
use APP\plugins\generic\fileManager\controllers\FileManagerHandler;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use PKP\security\Validation;

class FileManagerPlugin extends GenericPlugin
{
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', [$this, 'setPageHandler']);
            Hook::add('TemplateManager::display', [$this, 'handleTemplateDisplay']);
            Hook::add('Templates::Admin::Index::AdminFunctions', [$this, 'addAdminFunctions']);
        }
        return $success;
    }

    public function getDisplayName()
    {
        return __('plugins.generic.fileManager.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.fileManager.description');
    }

    public function isSitePlugin(): bool
    {
        return true;
    }

    public function getActions($request, $verb): array
    {
        $actions = parent::getActions($request, $verb);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $url = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, 'index', 'admin', 'filemanager');
        array_unshift($actions, new LinkAction(
            'openFileManager',
            new RedirectAction($url),
            __('plugins.generic.fileManager.displayName')
        ));
        return $actions;
    }

    public function setPageHandler(string $hookName, array $params): bool
    {
        $page = & $params[0];
        $op = & $params[1];
        $handler = & $params[3];

        if ($page === 'admin' && $op === 'filemanager') {
            $handler = new FileManagerHandler($this);
            return true;
        }

        if ($page === 'filemanager') {
            $handler = new FileManagerHandler($this);
            return true;
        }

        return false;
    }

    public function handleTemplateDisplay(string $hookName, array $params): bool
    {
        $templateMgr = $params[0];
        $request = Application::get()->getRequest();

        if (Validation::isLoggedIn()) {
            $context = $request->getContext();
            if ($context) {
                $menu = $templateMgr->getState('menu');
                if (is_array($menu) && !empty($menu)) {
                    $isManager = Validation::isAuthorized(Role::ROLE_ID_MANAGER, $context->getId());
                    $isAdmin = Validation::isSiteAdmin();

                    if ($isAdmin || $isManager) {
                        $url = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'filemanager');
                        $menu['fileManager'] = [
                            'name' => __('plugins.generic.fileManager.displayName'),
                            'url' => $url,
                            'isCurrent' => $request->getRequestedPage() === 'filemanager',
                        ];
                        $templateMgr->setState(['menu' => $menu]);
                    }
                }
            }
        }

        return false;
    }

    public function addAdminFunctions(string $hookName, array $params): bool
    {
        if (!Validation::isSiteAdmin()) {
            return false;
        }

        $output = & $params[2];
        $request = Application::get()->getRequest();
        $url = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, 'index', 'admin', 'filemanager');
        $title = htmlspecialchars(__('plugins.generic.fileManager.displayName'));
        $desc = htmlspecialchars(__('plugins.generic.fileManager.description'));

        $output .= '<action-panel>'
            . '<h2>' . $title . '</h2>'
            . '<p>' . $desc . '</p>'
            . '<template slot="actions">'
            . '<pkp-button element="a" href="' . htmlspecialchars($url) . '">' . $title . '</pkp-button>'
            . '</template>'
            . '</action-panel>';

        return false;
    }
}
