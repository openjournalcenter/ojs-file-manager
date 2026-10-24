<?php

namespace APP\plugins\generic\fileManager\controllers;

use APP\core\Application;
use APP\handler\Handler;
use APP\template\TemplateManager;
use PKP\config\Config;
use PKP\core\Core;
use PKP\core\JSONMessage;
use PKP\file\FileManager;
use PKP\security\Role;
use PKP\security\Validation;

class FileManagerHandler extends Handler
{
    public $_isBackendPage = true;

    public function __construct(public $plugin)
    {
        parent::__construct();
        $this->addRoleAssignment(
            [Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER],
            ['index', 'api', 'download', 'filemanager']
        );
    }

    public function authorize($request, &$args, $roleAssignments)
    {
        if (!Validation::isLoggedIn()) {
            Validation::redirectLogin();
            return false;
        }

        $isSiteAdmin = Validation::isSiteAdmin();
        $context = $request->getContext();
        $isManager = $context && Validation::isAuthorized(Role::ROLE_ID_MANAGER, $context->getId());

        if (!$isSiteAdmin && !$isManager) {
            return false;
        }

        $this->markRoleAssignmentsChecked();
        return parent::authorize($request, $args, $roleAssignments);
    }

    public function filemanager($args, $request)
    {
        $subOp = $args[0] ?? null;
        if ($subOp === 'api') {
            return $this->api($args, $request);
        }
        if ($subOp === 'download') {
            return $this->download($args, $request);
        }
        return $this->index($args, $request);
    }

    public function index($args, $request)
    {
        $templateMgr = TemplateManager::getManager($request);
        $this->setupTemplate($request);

        $context = $request->getContext();
        $isSiteAdmin = Validation::isSiteAdmin();

        $roots = [
            [
                'id' => 'public',
                'name' => 'Web Assets',
            ],
            [
                'id' => 'files',
                'name' => 'Journal Files',
            ],
        ];

        $apiUrl = $isSiteAdmin
            ? $request->getDispatcher()->url($request, Application::ROUTE_PAGE, 'index', 'admin', 'filemanager', ['api'])
            : $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'filemanager', 'api');

        $downloadUrl = $isSiteAdmin
            ? $request->getDispatcher()->url($request, Application::ROUTE_PAGE, 'index', 'admin', 'filemanager', ['download'])
            : $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'filemanager', 'download');

        $breadcrumbs = [];
        if ($isSiteAdmin) {
            $breadcrumbs[] = [
                'id' => 'admin',
                'url' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, 'index', 'admin'),
                'name' => __('navigation.admin'),
            ];
            $breadcrumbs[] = [
                'id' => 'filemanager',
                'url' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, 'index', 'admin', 'filemanager'),
                'name' => __('plugins.generic.fileManager.displayName'),
            ];
        } else {
            $breadcrumbs[] = [
                'id' => 'filemanager',
                'url' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'filemanager'),
                'name' => __('plugins.generic.fileManager.displayName'),
            ];
        }

        $templateMgr->assign([
            'pageTitle' => __('plugins.generic.fileManager.displayName'),
            'csrfToken' => $request->getSession()->getCSRFToken(),
            'apiUrl' => $apiUrl,
            'downloadUrl' => $downloadUrl,
            'roots' => $roots,
            'isSiteAdmin' => $isSiteAdmin,
            'pluginUrl' => $request->getBaseUrl() . '/' . $this->plugin->getPluginPath(),
            'fileManagerVersion' => time(),
            'breadcrumbs' => $breadcrumbs,
        ]);

        return $templateMgr->display($this->plugin->getTemplateResource('index.tpl'));
    }

    public function api($args, $request)
    {
        if (ob_get_length()) {
            ob_clean();
        }

        $action = $request->getUserVar('action') ?? 'list';
        $root = $request->getUserVar('root') ?? 'public';
        $path = (string) ($request->getUserVar('path') ?? '');

        try {
            switch ($action) {
                case 'list':
                    return $this->actionList($root, $path, $request);

                case 'search':
                    return $this->actionSearch($root, $path, $request);

                case 'mkdir':
                    if (!$request->checkCSRF()) {
                        return new JSONMessage(false, 'Invalid CSRF token');
                    }
                    return $this->actionMkdir($root, $path, $request);

                case 'upload':
                    if (!$request->checkCSRF()) {
                        return new JSONMessage(false, 'Invalid CSRF token');
                    }
                    return $this->actionUpload($root, $path, $request);

                case 'delete':
                    if (!$request->checkCSRF()) {
                        return new JSONMessage(false, 'Invalid CSRF token');
                    }
                    return $this->actionDelete($root, $path, $request);

                case 'rename':
                    if (!$request->checkCSRF()) {
                        return new JSONMessage(false, 'Invalid CSRF token');
                    }
                    return $this->actionRename($root, $path, $request);

                case 'copy':
                    if (!$request->checkCSRF()) {
                        return new JSONMessage(false, 'Invalid CSRF token');
                    }
                    return $this->actionCopy($root, $request);

                case 'move':
                    if (!$request->checkCSRF()) {
                        return new JSONMessage(false, 'Invalid CSRF token');
                    }
                    return $this->actionMove($root, $request);

                case 'read':
                    return $this->actionRead($root, $path, $request);

                case 'write':
                    if (!$request->checkCSRF()) {
                        return new JSONMessage(false, 'Invalid CSRF token');
                    }
                    return $this->actionWrite($root, $path, $request);

                case 'createFile':
                    if (!$request->checkCSRF()) {
                        return new JSONMessage(false, 'Invalid CSRF token');
                    }
                    return $this->actionCreateFile($root, $path, $request);

                default:
                    return new JSONMessage(false, 'Unknown action');
            }
        } catch (\Throwable $e) {
            error_log('[FileManager Error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return new JSONMessage(false, 'Operation failed. Please check server error log.');
        }
    }

    public function download($args, $request)
    {
        $root = (string) ($request->getUserVar('root') ?? 'public');
        $path = (string) ($request->getUserVar('path') ?? '');

        $target = $this->resolveSafePath($root, $path, $request);
        if (!$target || !is_file($target)) {
            $dispatcher = $request->getDispatcher();
            $dispatcher->handle404();
            return;
        }

        $baseName = basename($target);
        if ($this->isDangerousFilename($baseName)) {
            $dispatcher = $request->getDispatcher();
            $dispatcher->handle404();
            return;
        }

        $fileManager = new FileManager();
        $fileManager->downloadByPath($target, null, false, $baseName);
    }

    protected function actionList(string $root, string $path, $request): JSONMessage
    {
        $dir = $this->resolveSafePath($root, $path, $request);
        if (!$dir || !is_dir($dir)) {
            return new JSONMessage(false, 'Directory not found or access denied');
        }

        $entries = scandir($dir);
        $items = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            $isDir = is_dir($full);
            $size = $isDir ? 0 : (@filesize($full) ?: 0);
            $mtime = @filemtime($full) ?: time();

            $items[] = [
                'name' => $entry,
                'isDir' => $isDir,
                'size' => $size,
                'formattedSize' => $isDir ? '-' : $this->formatBytes($size),
                'mtime' => date('Y-m-d H:i:s', $mtime),
                'extension' => strtolower(pathinfo($entry, PATHINFO_EXTENSION)),
                'isEditable' => !$isDir && $this->isEditableExtension($entry),
                'isImage' => !$isDir && $this->isImageExtension($entry),
            ];
        }

        usort($items, function ($a, $b) {
            if ($a['isDir'] !== $b['isDir']) {
                return $a['isDir'] ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return new JSONMessage(true, [
            'root' => $root,
            'path' => $this->normalizePath($path),
            'items' => $items,
        ]);
    }

    protected function actionSearch(string $root, string $path, $request): JSONMessage
    {
        $query = trim((string) $request->getUserVar('query'));
        if ($query === '') {
            return new JSONMessage(false, 'Search query cannot be empty');
        }

        $dir = $this->resolveSafePath($root, $path, $request);
        if (!$dir || !is_dir($dir)) {
            return new JSONMessage(false, 'Directory not found or access denied');
        }

        $rootPath = $this->getRootPath($root, $request);
        if (!$rootPath) {
            return new JSONMessage(false, 'Invalid storage root');
        }
        $rootPathLen = strlen(rtrim($rootPath, DIRECTORY_SEPARATOR . '/'));

        $items = [];
        $maxResults = 250;

        try {
            $flags = \FilesystemIterator::SKIP_DOTS;
            $dirIterator = new \RecursiveDirectoryIterator($dir, $flags);
            $iterator = new \RecursiveIteratorIterator(
                $dirIterator,
                \RecursiveIteratorIterator::SELF_FIRST,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            $iterator->setMaxDepth(10);

            foreach ($iterator as $fileInfo) {
                if (count($items) >= $maxResults) {
                    break;
                }

                $filename = $fileInfo->getFilename();
                if ($filename === '.' || $filename === '..' || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\..*)?$/i', $filename)) {
                    continue;
                }

                if (stripos($filename, $query) !== false) {
                    $real = $fileInfo->getRealPath();
                    if (!$real) {
                        continue;
                    }

                    $realNorm = rtrim(str_replace('\\', '/', $real), '/');
                    $rootNorm = rtrim(str_replace('\\', '/', $rootPath), '/');
                    $cmpReal = PHP_OS_FAMILY === 'Windows' ? strtolower($realNorm) : $realNorm;
                    $cmpRoot = PHP_OS_FAMILY === 'Windows' ? strtolower($rootNorm) : $rootNorm;
                    if ($cmpReal !== $cmpRoot && !str_starts_with($cmpReal, $cmpRoot . '/')) {
                        continue;
                    }

                    $relFullPath = trim(str_replace('\\', '/', substr($real, $rootPathLen)), '/');
                    $isDir = $fileInfo->isDir();
                    $size = $isDir ? 0 : (@$fileInfo->getSize() ?: 0);
                    $mtime = @$fileInfo->getMTime() ?: time();
                    $relParent = dirname($relFullPath);
                    if ($relParent === '.' || $relParent === '/' || $relParent === '\\') {
                        $relParent = '';
                    }

                    $items[] = [
                        'name' => $filename,
                        'path' => $relParent,
                        'fullPath' => $relFullPath,
                        'isDir' => $isDir,
                        'size' => $size,
                        'formattedSize' => $isDir ? '-' : $this->formatBytes($size),
                        'mtime' => date('Y-m-d H:i:s', $mtime),
                        'extension' => strtolower($fileInfo->getExtension()),
                        'isEditable' => !$isDir && $this->isEditableExtension($filename),
                        'isImage' => !$isDir && $this->isImageExtension($filename),
                    ];
                }
            }
        } catch (\Throwable $e) {
            error_log('[FileManager Search] ' . $e->getMessage());
        }

        usort($items, function ($a, $b) {
            if ($a['isDir'] !== $b['isDir']) {
                return $a['isDir'] ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return new JSONMessage(true, [
            'root' => $root,
            'query' => $query,
            'path' => $this->normalizePath($path),
            'items' => $items,
        ]);
    }

    protected function actionMkdir(string $root, string $path, $request): JSONMessage
    {
        $folderName = trim((string) $request->getUserVar('folderName'));
        if (!$this->isValidItemName($folderName) || $this->isDangerousFilename($folderName)) {
            return new JSONMessage(false, 'Invalid or forbidden folder name');
        }

        $parentDir = $this->resolveSafePath($root, $path, $request);
        if (!$parentDir || !is_dir($parentDir)) {
            return new JSONMessage(false, 'Parent directory not found');
        }

        $newDir = $parentDir . DIRECTORY_SEPARATOR . $folderName;
        if (file_exists($newDir)) {
            return new JSONMessage(false, 'Folder already exists');
        }

        if (!@mkdir($newDir, 0755)) {
            return new JSONMessage(false, 'Failed to create folder');
        }

        return new JSONMessage(true, ['message' => 'Folder created successfully']);
    }

    protected function actionCreateFile(string $root, string $path, $request): JSONMessage
    {
        $fileName = trim((string) $request->getUserVar('fileName'));
        if (!$this->isValidItemName($fileName) || $this->isDangerousFilename($fileName)) {
            return new JSONMessage(false, 'Invalid or forbidden file name');
        }

        if (!$this->isEditableExtension($fileName)) {
            return new JSONMessage(false, 'File type not allowed. Allowed types: txt, css, js, json, xml, md, html, htm, csv, svg');
        }

        $parentDir = $this->resolveSafePath($root, $path, $request);
        if (!$parentDir || !is_dir($parentDir)) {
            return new JSONMessage(false, 'Parent directory not found');
        }

        $newFile = $parentDir . DIRECTORY_SEPARATOR . $fileName;
        if (file_exists($newFile)) {
            return new JSONMessage(false, 'File already exists');
        }

        $content = (string) ($request->getUserVar('content') ?? '');
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (in_array($ext, ['svg', 'html', 'htm'], true) && $this->containsDangerousScript($content)) {
            return new JSONMessage(false, 'Unsafe script content or entity blocked');
        }

        if (@file_put_contents($newFile, $content) === false) {
            return new JSONMessage(false, 'Failed to create file');
        }

        return new JSONMessage(true, ['message' => 'File created successfully']);
    }

    protected function actionUpload(string $root, string $path, $request): JSONMessage
    {
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            return new JSONMessage(false, 'File upload error or no file provided');
        }

        $filename = basename($_FILES['file']['name']);
        $filename = trim($filename, " .\t\n\r\0\x0B");

        if (!$this->isValidItemName($filename) || $this->isDangerousFilename($filename)) {
            return new JSONMessage(false, 'File name or extension is blocked for security');
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, ['svg', 'html', 'htm'], true)) {
            $fileContent = @file_get_contents($_FILES['file']['tmp_name']);
            if ($fileContent !== false && $this->containsDangerousScript($fileContent)) {
                return new JSONMessage(false, 'File contains potentially unsafe script content or entities');
            }
        }

        $targetDir = $this->resolveSafePath($root, $path, $request);
        if (!$targetDir || !is_dir($targetDir)) {
            return new JSONMessage(false, 'Target directory not found');
        }

        $destination = $targetDir . DIRECTORY_SEPARATOR . $filename;
        if (!@move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
            return new JSONMessage(false, 'Failed to move uploaded file');
        }

        return new JSONMessage(true, ['message' => 'File uploaded successfully']);
    }

    protected function actionDelete(string $root, string $path, $request): JSONMessage
    {
        $itemsJson = $request->getUserVar('items');
        $items = [];
        if ($itemsJson) {
            $decoded = is_array($itemsJson) ? $itemsJson : json_decode((string) $itemsJson, true);
            if (is_array($decoded)) {
                $items = $decoded;
            }
        }
        if (empty($items) && $path !== '') {
            $items = [$path];
        }

        if (empty($items)) {
            return new JSONMessage(false, 'No items specified for deletion');
        }

        $deletedCount = 0;
        $base = $this->getRootPath($root, $request);
        $realBase = $base ? realpath($base) : null;

        foreach ($items as $itemPath) {
            $normalized = $this->normalizePath((string) $itemPath);
            if ($normalized === '') {
                continue;
            }

            $target = $this->resolveSafePath($root, $normalized, $request);
            if ($target && file_exists($target)) {
                if ($realBase && realpath($target) === $realBase) {
                    continue;
                }
                if ($this->deleteRecursive($target)) {
                    $deletedCount++;
                }
            }
        }

        if ($deletedCount === 0) {
            return new JSONMessage(false, 'Failed to delete selected item(s)');
        }

        return new JSONMessage(true, ['message' => $deletedCount . ' item(s) deleted successfully']);
    }

    protected function actionCopy(string $root, $request): JSONMessage
    {
        $destPath = (string) ($request->getUserVar('destination') ?? '');
        $itemsJson = $request->getUserVar('items');
        $items = [];
        if ($itemsJson) {
            $decoded = is_array($itemsJson) ? $itemsJson : json_decode((string) $itemsJson, true);
            if (is_array($decoded)) {
                $items = $decoded;
            }
        }
        $singlePath = (string) ($request->getUserVar('path') ?? '');
        if (empty($items) && $singlePath !== '') {
            $items = [$singlePath];
        }

        if (empty($items)) {
            return new JSONMessage(false, 'No items specified to copy');
        }

        $destDir = $this->resolveSafePath($root, $destPath, $request);
        if (!$destDir || !is_dir($destDir)) {
            return new JSONMessage(false, 'Destination directory not found');
        }

        $copiedCount = 0;
        foreach ($items as $itemPath) {
            $normalized = $this->normalizePath((string) $itemPath);
            if ($normalized === '') {
                continue;
            }

            $src = $this->resolveSafePath($root, $normalized, $request);
            if (!$src || !file_exists($src)) {
                continue;
            }

            $itemName = basename($src);
            if (!$this->isValidItemName($itemName) || $this->isDangerousFilename($itemName)) {
                continue;
            }

            if (is_dir($src)) {
                $srcNorm = rtrim(str_replace('\\', '/', realpath($src) ?: $src), '/');
                $destNorm = rtrim(str_replace('\\', '/', realpath($destDir) ?: $destDir), '/');
                $cmpSrc = PHP_OS_FAMILY === 'Windows' ? strtolower($srcNorm) : $srcNorm;
                $cmpDest = PHP_OS_FAMILY === 'Windows' ? strtolower($destNorm) : $destNorm;
                if ($cmpDest === $cmpSrc || str_starts_with($cmpDest, $cmpSrc . '/')) {
                    continue;
                }
            }

            $dst = $destDir . DIRECTORY_SEPARATOR . $itemName;
            if (file_exists($dst)) {
                $dst = $this->getUniqueCopyPath($destDir, $itemName);
            }

            if ($this->copyRecursive($src, $dst)) {
                $copiedCount++;
            }
        }

        if ($copiedCount === 0) {
            return new JSONMessage(false, 'Failed to copy item(s)');
        }

        return new JSONMessage(true, ['message' => $copiedCount . ' item(s) copied successfully']);
    }

    protected function actionMove(string $root, $request): JSONMessage
    {
        $destPath = (string) ($request->getUserVar('destination') ?? '');
        $itemsJson = $request->getUserVar('items');
        $items = [];
        if ($itemsJson) {
            $decoded = is_array($itemsJson) ? $itemsJson : json_decode((string) $itemsJson, true);
            if (is_array($decoded)) {
                $items = $decoded;
            }
        }
        $singlePath = (string) ($request->getUserVar('path') ?? '');
        if (empty($items) && $singlePath !== '') {
            $items = [$singlePath];
        }

        if (empty($items)) {
            return new JSONMessage(false, 'No items specified to move');
        }

        $destDir = $this->resolveSafePath($root, $destPath, $request);
        if (!$destDir || !is_dir($destDir)) {
            return new JSONMessage(false, 'Destination directory not found');
        }

        $movedCount = 0;
        foreach ($items as $itemPath) {
            $normalized = $this->normalizePath((string) $itemPath);
            if ($normalized === '') {
                continue;
            }

            $src = $this->resolveSafePath($root, $normalized, $request);
            if (!$src || !file_exists($src)) {
                continue;
            }

            $itemName = basename($src);
            if (!$this->isValidItemName($itemName) || $this->isDangerousFilename($itemName)) {
                continue;
            }

            if (realpath(dirname($src)) === realpath($destDir)) {
                continue;
            }

            if (is_dir($src)) {
                $srcNorm = rtrim(str_replace('\\', '/', realpath($src) ?: $src), '/');
                $destNorm = rtrim(str_replace('\\', '/', realpath($destDir) ?: $destDir), '/');
                $cmpSrc = PHP_OS_FAMILY === 'Windows' ? strtolower($srcNorm) : $srcNorm;
                $cmpDest = PHP_OS_FAMILY === 'Windows' ? strtolower($destNorm) : $destNorm;
                if ($cmpDest === $cmpSrc || str_starts_with($cmpDest, $cmpSrc . '/')) {
                    continue;
                }
            }

            $dst = $destDir . DIRECTORY_SEPARATOR . $itemName;
            if (file_exists($dst)) {
                continue;
            }

            if (@rename($src, $dst)) {
                $movedCount++;
            } elseif ($this->copyRecursive($src, $dst)) {
                $this->deleteRecursive($src);
                $movedCount++;
            }
        }

        if ($movedCount === 0) {
            return new JSONMessage(false, 'Failed to move item(s) (target may already exist or cannot move to same folder)');
        }

        return new JSONMessage(true, ['message' => $movedCount . ' item(s) moved successfully']);
    }

    protected function actionRename(string $root, string $path, $request): JSONMessage
    {
        $normalized = $this->normalizePath($path);
        if ($normalized === '') {
            return new JSONMessage(false, 'Root directory cannot be renamed');
        }

        $newName = trim((string) $request->getUserVar('newName'));
        $newName = trim($newName, " .\t\n\r\0\x0B");

        if (!$this->isValidItemName($newName) || $this->isDangerousFilename($newName)) {
            return new JSONMessage(false, 'Forbidden or invalid file name');
        }

        $target = $this->resolveSafePath($root, $path, $request);
        if (!$target || !file_exists($target)) {
            return new JSONMessage(false, 'Target not found');
        }

        $targetBase = basename($target);
        if ($this->isDangerousFilename($targetBase)) {
            return new JSONMessage(false, 'Cannot rename protected system item');
        }

        $dest = dirname($target) . DIRECTORY_SEPARATOR . $newName;
        if (file_exists($dest)) {
            return new JSONMessage(false, 'Destination name already exists');
        }

        if (!@rename($target, $dest)) {
            return new JSONMessage(false, 'Failed to rename');
        }

        return new JSONMessage(true, ['message' => 'Renamed successfully']);
    }

    protected function actionRead(string $root, string $path, $request): JSONMessage
    {
        $target = $this->resolveSafePath($root, $path, $request);
        if (!$target || !is_file($target)) {
            return new JSONMessage(false, 'File not found');
        }

        if (!$this->isEditableExtension(basename($target))) {
            return new JSONMessage(false, 'File format not supported for text editing');
        }

        if (filesize($target) > 5 * 1024 * 1024) {
            return new JSONMessage(false, 'File is too large to edit (> 5MB)');
        }

        $content = @file_get_contents($target);
        if ($content === false) {
            return new JSONMessage(false, 'Failed to read file');
        }

        return new JSONMessage(true, [
            'name' => basename($target),
            'content' => $content,
        ]);
    }

    protected function actionWrite(string $root, string $path, $request): JSONMessage
    {
        $target = $this->resolveSafePath($root, $path, $request);
        if (!$target || !is_file($target)) {
            return new JSONMessage(false, 'Target file not found');
        }

        $baseName = basename($target);
        if (!$this->isEditableExtension($baseName) || $this->isDangerousFilename($baseName)) {
            return new JSONMessage(false, 'File format not allowed for editing');
        }

        $content = (string) $request->getUserVar('content');
        $ext = strtolower(pathinfo($baseName, PATHINFO_EXTENSION));
        if (in_array($ext, ['svg', 'html', 'htm'], true) && $this->containsDangerousScript($content)) {
            return new JSONMessage(false, 'Unsafe script content or entity blocked');
        }

        if (@file_put_contents($target, $content) === false) {
            return new JSONMessage(false, 'Failed to save file');
        }

        return new JSONMessage(true, ['message' => 'Saved successfully']);
    }

    protected function getRootPath(string $rootType, $request): ?string
    {
        $isSiteAdmin = Validation::isSiteAdmin();
        $context = $request->getContext();
        $contextId = $context ? $context->getId() : 0;

        $publicDirName = Config::getVar('files', 'public_files_dir') ?: 'public';
        $publicBase = Core::getBaseDir() . DIRECTORY_SEPARATOR . $publicDirName;
        $filesBase = Config::getVar('files', 'files_dir');

        if ($rootType === 'public') {
            if ($isSiteAdmin && !$contextId) {
                return realpath($publicBase) ?: $publicBase;
            }
            if ($contextId > 0) {
                $dir = $publicBase . DIRECTORY_SEPARATOR . 'journals' . DIRECTORY_SEPARATOR . $contextId;
                if (!file_exists($dir)) {
                    @mkdir($dir, 0755, true);
                }
                return realpath($dir) ?: $dir;
            }
            return null;
        }

        if ($rootType === 'files') {
            if ($isSiteAdmin && !$contextId) {
                return realpath($filesBase) ?: $filesBase;
            }
            if ($contextId > 0) {
                $dir = $filesBase . DIRECTORY_SEPARATOR . 'journals' . DIRECTORY_SEPARATOR . $contextId;
                if (!file_exists($dir)) {
                    @mkdir($dir, 0755, true);
                }
                return realpath($dir) ?: $dir;
            }
            return null;
        }

        return null;
    }

    protected function normalizePath(string $path): string
    {
        $clean = str_replace(["\0", '\\'], ['', '/'], $path);
        $parts = array_filter(explode('/', $clean), function ($part) {
            return $part !== '' && $part !== '.' && $part !== '..';
        });
        return implode('/', $parts);
    }

    protected function resolveSafePath(string $rootType, string $subpath, $request): ?string
    {
        $base = $this->getRootPath($rootType, $request);
        if (!$base) {
            return null;
        }

        $cleanSubpath = $this->normalizePath($subpath);
        $target = $base;
        if ($cleanSubpath !== '') {
            $target .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanSubpath);
        }

        $realBase = realpath($base);
        if (!$realBase) {
            return null;
        }

        if (file_exists($target)) {
            $realTarget = realpath($target);
            if (!$realTarget) {
                return null;
            }
            $targetNorm = rtrim(str_replace('\\', '/', $realTarget), '/');
            $baseNorm = rtrim(str_replace('\\', '/', $realBase), '/');
            $cmpTarget = PHP_OS_FAMILY === 'Windows' ? strtolower($targetNorm) : $targetNorm;
            $cmpBase = PHP_OS_FAMILY === 'Windows' ? strtolower($baseNorm) : $baseNorm;
            if ($cmpTarget !== $cmpBase && !str_starts_with($cmpTarget, $cmpBase . '/')) {
                return null;
            }
            return $realTarget;
        }

        $parent = dirname($target);
        $realParent = realpath($parent);
        if (!$realParent) {
            return null;
        }
        $parentNorm = rtrim(str_replace('\\', '/', $realParent), '/');
        $baseNorm = rtrim(str_replace('\\', '/', $realBase), '/');
        $cmpParent = PHP_OS_FAMILY === 'Windows' ? strtolower($parentNorm) : $parentNorm;
        $cmpBase = PHP_OS_FAMILY === 'Windows' ? strtolower($baseNorm) : $baseNorm;
        if ($cmpParent !== $cmpBase && !str_starts_with($cmpParent, $cmpBase . '/')) {
            return null;
        }

        return $target;
    }

    protected function isValidItemName(string $name): bool
    {
        if ($name === '' || $name === '.' || $name === '..') {
            return false;
        }

        if (preg_match('/[<>:"\/\\\\|?*\x00-\x1f]/', $name)) {
            return false;
        }

        if (preg_match('/[ .]$/', $name)) {
            return false;
        }

        if (preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\..*)?$/i', $name)) {
            return false;
        }

        return true;
    }

    protected function isDangerousFilename(string $filename): bool
    {
        $clean = trim($filename, " .\t\n\r\0\x0B");
        if ($clean === '') {
            return true;
        }

        $lower = strtolower($clean);

        $blockedExact = [
            '.htaccess', '.htpasswd', '.user.ini', 'php.ini', 'web.config',
            '.env', '.git', '.gitignore', '.gitlab-ci.yml', '.bashrc', '.profile'
        ];
        if (in_array($lower, $blockedExact, true)) {
            return true;
        }

        $dangerous = [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phtm', 'phar',
            'phps', 'pht', 'inc', 'cgi', 'pl', 'py', 'sh', 'bash', 'exe',
            'bat', 'cmd', 'vbs', 'ps1', 'psm1', 'htaccess', 'htpasswd', 'env',
            'config', 'ini', 'jsp', 'asp', 'aspx', 'cer', 'asa', 'asax',
            'shtml', 'shtm', 'swf', 'svgz', 'cfm', 'cfc', 'com', 'scr',
            'msi', 'dll', 'so', 'dylib', 'bin', 'jar', 'war', 'ear',
            'vbe', 'jse', 'wsf', 'wsh', 'hta', 'action', 'do'
        ];

        $ext = pathinfo($lower, PATHINFO_EXTENSION);
        if (in_array($ext, $dangerous, true)) {
            return true;
        }

        $segments = explode('.', $lower);
        foreach ($segments as $segment) {
            if (in_array($segment, $dangerous, true)) {
                return true;
            }
        }

        return false;
    }

    protected function isDangerousExtension(string $filename): bool
    {
        return $this->isDangerousFilename($filename);
    }

    protected function containsDangerousScript(string $content): bool
    {
        $patterns = [
            '/<\s*script\b/i',
            '/javascript\s*:/i',
            '/vbscript\s*:/i',
            '/data\s*:\s*(text\/html|image\/svg\+xml)/i',
            '/\bon[a-z]+\s*=/i',
            '/<\s*!\s*ENTITY\b/i',
            '/<\s*!\s*DOCTYPE\b[^>]*SYSTEM/i',
            '/<\s*embed\b/i',
            '/<\s*object\b/i',
            '/<\s*iframe\b/i',
            '/<\s*base\b/i',
            '/<\?\s*php\b/i',
            '/<\?=/i',
            '/<\s*%/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    protected function isEditableExtension(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $editable = ['txt', 'css', 'js', 'json', 'xml', 'md', 'html', 'htm', 'csv', 'svg'];
        return in_array($ext, $editable, true);
    }

    protected function isImageExtension(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true);
    }

    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    protected function deleteRecursive(string $target): bool
    {
        if (is_link($target)) {
            return @unlink($target);
        }
        if (is_dir($target)) {
            $files = array_diff(scandir($target), ['.', '..']);
            foreach ($files as $file) {
                $this->deleteRecursive($target . DIRECTORY_SEPARATOR . $file);
            }
            return @rmdir($target);
        } elseif (is_file($target)) {
            return @unlink($target);
        }
        return false;
    }

    protected function copyRecursive(string $src, string $dst): bool
    {
        if (is_link($src)) {
            return false;
        }

        if (is_dir($src)) {
            if (!file_exists($dst) && !@mkdir($dst, 0755, true)) {
                return false;
            }
            $files = array_diff(scandir($src), ['.', '..']);
            foreach ($files as $file) {
                if (!$this->copyRecursive($src . DIRECTORY_SEPARATOR . $file, $dst . DIRECTORY_SEPARATOR . $file)) {
                    return false;
                }
            }
            return true;
        } elseif (is_file($src)) {
            return @copy($src, $dst);
        }

        return false;
    }

    protected function getUniqueCopyPath(string $destDir, string $itemName): string
    {
        $info = pathinfo($itemName);
        $name = $info['filename'];
        $ext = isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '';

        $counter = 1;
        do {
            $newName = $name . ' - Copy' . ($counter > 1 ? " ($counter)" : '') . $ext;
            $dst = $destDir . DIRECTORY_SEPARATOR . $newName;
            $counter++;
        } while (file_exists($dst) && $counter < 100);

        return $dst;
    }
}
