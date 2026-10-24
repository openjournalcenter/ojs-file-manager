(function() {
    'use strict';

    if (window.__fileManagerInitialized) {
        return;
    }

    function initFileManager() {
        if (!window.fmConfig) {
            console.warn('[FileManager] fmConfig not found');
            return;
        }

        const tableBody = document.getElementById('fmTableBody');
        if (!tableBody) {
            return;
        }

        window.__fileManagerInitialized = true;
        const config = window.fmConfig;
        let currentRoot = config.initialRoot || 'public';
        let currentPath = '';
        let selectedPaths = new Set();
        let lastSelectedPath = null;
        let allCurrentItems = [];
        let displayedItems = [];
        let isSearchingDeep = false;

        const rootSelect = document.getElementById('fmRootSelect');
        const breadcrumbs = document.getElementById('fmBreadcrumbs');
        const searchInput = document.getElementById('fmSearchInput');
        const checkAll = document.getElementById('fmCheckAll');
        const btnNewFolder = document.getElementById('fmBtnNewFolder');
        const btnNewFile = document.getElementById('fmBtnNewFile');
        const fileInput = document.getElementById('fmFileInput');
        const btnCopy = document.getElementById('fmBtnCopy');
        const btnMove = document.getElementById('fmBtnMove');
        const btnDeleteSelected = document.getElementById('fmBtnDeleteSelected');
        const btnSelectAll = document.getElementById('fmBtnSelectAll');
        const btnUnselectAll = document.getElementById('fmBtnUnselectAll');
        const btnRefresh = document.getElementById('fmBtnRefresh');
        const dropzone = document.getElementById('fmDropzone');

        const modalFolder = document.getElementById('fmModalFolder');
        const inputFolderName = document.getElementById('fmNewFolderName');
        const btnSubmitFolder = document.getElementById('fmSubmitNewFolder');

        const modalFile = document.getElementById('fmModalFile');
        const inputFileName = document.getElementById('fmNewFileName');
        const btnSubmitFile = document.getElementById('fmSubmitNewFile');

        const modalRename = document.getElementById('fmModalRename');
        const inputRenameOldPath = document.getElementById('fmRenameOldPath');
        const inputRenameNewName = document.getElementById('fmRenameNewName');
        const btnSubmitRename = document.getElementById('fmSubmitRename');

        const modalDelete = document.getElementById('fmModalDelete');
        const deletePrompt = document.getElementById('fmDeletePrompt');
        const deleteTitle = document.getElementById('fmDeleteTitle');
        const btnSubmitDelete = document.getElementById('fmSubmitDelete');
        let pendingDelete = null;

        const modalCopy = document.getElementById('fmModalCopy');
        const inputCopyDestination = document.getElementById('fmCopyDestination');
        const btnSubmitCopy = document.getElementById('fmSubmitCopy');

        const modalMove = document.getElementById('fmModalMove');
        const inputMoveDestination = document.getElementById('fmMoveDestination');
        const btnSubmitMove = document.getElementById('fmSubmitMove');

        const modalEditor = document.getElementById('fmModalEditor');
        const editorTitle = document.getElementById('fmEditorTitle');
        const inputEditorPath = document.getElementById('fmEditorPath');
        const textareaEditor = document.getElementById('fmEditorTextarea');
        const btnSubmitSaveFile = document.getElementById('fmSubmitSaveFile');

        const modalImage = document.getElementById('fmModalImage');
        const imageTitle = document.getElementById('fmImageTitle');
        const imagePreview = document.getElementById('fmImagePreview');

        function openModal(modal) {
            if (modal) modal.classList.add('fm-active');
        }

        function closeModal(modal) {
            if (modal) modal.classList.remove('fm-active');
        }

        document.querySelectorAll('.fm-modal-close, .fm-modal-cancel').forEach(btn => {
            btn.addEventListener('click', function() {
                const modal = this.closest('.fm-modal');
                if (modal) closeModal(modal);
            });
        });

        document.querySelectorAll('.fm-modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeModal(modal);
            });
        });

        function showLoading() {
            tableBody.innerHTML = '<tr><td colspan="5" class="fm-loading">Loading items...</td></tr>';
        }

        function resolveUrl(rawUrl) {
            try {
                const url = new URL(rawUrl, window.location.origin);
                url.protocol = window.location.protocol;
                url.hostname = window.location.hostname;
                url.port = window.location.port;
                return url;
            } catch (e) {
                return new URL(rawUrl, window.location.origin);
            }
        }

        function request(action, params = {}, method = 'GET') {
            const url = resolveUrl(config.apiUrl);
            url.searchParams.set('action', action);
            url.searchParams.set('root', currentRoot);

            let options = { 
                method,
                credentials: 'same-origin'
            };

            if (method === 'GET') {
                for (const key in params) {
                    if (params[key] !== undefined && params[key] !== null) {
                        url.searchParams.set(key, params[key]);
                    }
                }
            } else {
                const formData = new FormData();
                formData.append('csrfToken', config.csrfToken);
                formData.append('root', currentRoot);
                for (const key in params) {
                    formData.append(key, params[key]);
                }
                options.body = formData;
            }

            return fetch(url.toString(), options)
                .then(async res => {
                    const text = await res.text();
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        throw new Error('Server returned invalid response: ' + text.substring(0, 160));
                    }
                    if (!data.status) {
                        throw new Error(data.content || 'Operation failed');
                    }
                    return data.content;
                });
        }

        function updateSelectionUI() {
            const rows = tableBody.querySelectorAll('tr');
            let totalItems = 0;
            let selectedCount = 0;

            rows.forEach(r => {
                const check = r.querySelector('.fm-item-check');
                if (!check) return;
                totalItems++;
                const p = check.getAttribute('data-path');
                if (selectedPaths.has(p)) {
                    check.checked = true;
                    r.classList.add('fm-row-selected');
                    selectedCount++;
                } else {
                    check.checked = false;
                    r.classList.remove('fm-row-selected');
                }
            });

            if (checkAll) {
                if (totalItems > 0 && selectedCount === totalItems) {
                    checkAll.checked = true;
                    checkAll.indeterminate = false;
                } else if (selectedCount > 0) {
                    checkAll.checked = false;
                    checkAll.indeterminate = true;
                } else {
                    checkAll.checked = false;
                    checkAll.indeterminate = false;
                }
            }
        }

        function selectAllItems() {
            displayedItems.forEach(item => {
                const itemPath = item.fullPath !== undefined ? item.fullPath : (currentPath ? `${currentPath}/${item.name}` : item.name);
                selectedPaths.add(itemPath);
            });
            updateSelectionUI();
        }

        function unselectAllItems() {
            selectedPaths.clear();
            updateSelectionUI();
        }

        function loadFolder(path) {
            currentPath = path || '';
            isSearchingDeep = false;
            if (searchInput) searchInput.value = '';
            selectedPaths.clear();
            lastSelectedPath = null;
            if (checkAll) {
                checkAll.checked = false;
                checkAll.indeterminate = false;
            }

            showLoading();
            renderBreadcrumbs(currentPath);

            request('list', { path: currentPath })
                .then(data => {
                    allCurrentItems = data.items || [];
                    renderTable(allCurrentItems);
                })
                .catch(err => {
                    console.error('[FileManager] Load error:', err);
                    tableBody.innerHTML = `<tr><td colspan="5" class="fm-empty text-danger" style="color:#d9534f; padding: 20px; text-align: center;">Error: ${escapeHtml(err.message)}</td></tr>`;
                });
        }

        function renderBreadcrumbs(path, searchQuery) {
            if (!breadcrumbs) return;
            breadcrumbs.innerHTML = '';

            const rootCrumb = document.createElement('span');
            rootCrumb.className = 'fm-crumb';
            rootCrumb.innerHTML = '<i class="fa fa-home"></i> Home';
            rootCrumb.addEventListener('click', () => loadFolder(''));
            breadcrumbs.appendChild(rootCrumb);

            if (path) {
                const parts = path.split('/').filter(p => p.length > 0);
                let accumulated = '';

                parts.forEach((part, index) => {
                    const sep = document.createElement('span');
                    sep.className = 'fm-crumb-sep';
                    sep.textContent = '/';
                    breadcrumbs.appendChild(sep);

                    accumulated += (index > 0 ? '/' : '') + part;
                    const thisPath = accumulated;

                    const crumb = document.createElement('span');
                    crumb.className = 'fm-crumb';
                    crumb.textContent = part;
                    crumb.addEventListener('click', () => loadFolder(thisPath));
                    breadcrumbs.appendChild(crumb);
                });
            }

            if (searchQuery) {
                const sep = document.createElement('span');
                sep.className = 'fm-crumb-sep';
                sep.textContent = '/';
                breadcrumbs.appendChild(sep);

                const searchCrumb = document.createElement('span');
                searchCrumb.className = 'fm-crumb';
                searchCrumb.style.cursor = 'default';
                searchCrumb.style.color = '#333';
                searchCrumb.innerHTML = `Search: "${escapeHtml(searchQuery)}" <a href="#" style="margin-left:6px; color:#d9534f; text-decoration:none;" title="Close search">&times;</a>`;
                searchCrumb.querySelector('a').addEventListener('click', (e) => {
                    e.preventDefault();
                    if (searchInput) searchInput.value = '';
                    loadFolder(currentPath);
                });
                breadcrumbs.appendChild(searchCrumb);
            }
        }

        function renderTable(items, isSearchResult = false) {
            tableBody.innerHTML = '';
            displayedItems = items || [];
            selectedPaths.clear();
            lastSelectedPath = null;
            if (checkAll) {
                checkAll.checked = false;
                checkAll.indeterminate = false;
            }

            if (!displayedItems || displayedItems.length === 0) {
                tableBody.innerHTML = isSearchResult
                    ? '<tr><td colspan="5" class="fm-empty">No files or folders found</td></tr>'
                    : '<tr><td colspan="5" class="fm-empty">Folder is empty</td></tr>';
                return;
            }

            displayedItems.forEach(item => {
                const tr = document.createElement('tr');
                const itemPath = item.fullPath !== undefined ? item.fullPath : (currentPath ? `${currentPath}/${item.name}` : item.name);

                let iconClass = 'fa-file-o';
                if (item.isDir) {
                    iconClass = 'fa-folder';
                } else if (item.isImage) {
                    iconClass = 'fa-file-image-o';
                } else if (item.isEditable) {
                    iconClass = 'fa-file-code-o';
                }

                const dlUrlObj = resolveUrl(config.downloadUrl);
                dlUrlObj.searchParams.set('root', currentRoot);
                dlUrlObj.searchParams.set('path', itemPath);
                const downloadUrl = dlUrlObj.toString();

                let actionsHtml = '';
                if (!item.isDir) {
                    actionsHtml += `<a href="${escapeHtml(downloadUrl)}" class="fm-btn-action" title="Download"><span class="fa fa-download"></span></a>`;
                    if (item.isEditable) {
                        actionsHtml += `<button type="button" class="fm-btn-action fm-btn-edit" title="Edit"><span class="fa fa-edit"></span></button>`;
                    }
                    if (item.isImage) {
                        actionsHtml += `<button type="button" class="fm-btn-action fm-btn-view" title="Preview"><span class="fa fa-eye"></span></button>`;
                    }
                }
                actionsHtml += `<button type="button" class="fm-btn-action fm-btn-rename" title="Rename"><span class="fa fa-pencil"></span></button>`;
                actionsHtml += `<button type="button" class="fm-btn-action fm-btn-danger fm-btn-delete" title="Delete"><span class="fa fa-trash"></span></button>`;

                const subpathHtml = (isSearchResult && item.path) 
                    ? `<span class="fm-item-subpath">(${escapeHtml(item.path)})</span>` 
                    : '';

                tr.innerHTML = `
                    <td class="fm-col-check">
                        <input type="checkbox" class="fm-item-check" data-path="${escapeHtml(itemPath)}">
                    </td>
                    <td>
                        <div class="fm-item-name ${item.isDir ? 'fm-is-dir' : ''}">
                            <span class="fa ${iconClass} fm-item-icon"></span>
                            <span class="fm-item-label">${escapeHtml(item.name)}</span>
                            ${subpathHtml}
                        </div>
                    </td>
                    <td class="fm-col-size">${escapeHtml(item.formattedSize)}</td>
                    <td class="fm-col-date">${escapeHtml(item.mtime)}</td>
                    <td class="fm-col-actions">${actionsHtml}</td>
                `;

                tr.addEventListener('click', function(e) {
                    if (e.target.closest('.fm-col-actions')) {
                        return;
                    }

                    const checkEl = tr.querySelector('.fm-item-check');
                    const isCheckboxClick = e.target.classList.contains('fm-item-check');

                    if (isCheckboxClick) {
                        if (checkEl.checked) {
                            selectedPaths.add(itemPath);
                        } else {
                            selectedPaths.delete(itemPath);
                        }
                    } else if (e.ctrlKey || e.metaKey) {
                        if (selectedPaths.has(itemPath)) {
                            selectedPaths.delete(itemPath);
                        } else {
                            selectedPaths.add(itemPath);
                        }
                    } else if (e.shiftKey && lastSelectedPath) {
                        const paths = displayedItems.map(it => it.fullPath !== undefined ? it.fullPath : (currentPath ? `${currentPath}/${it.name}` : it.name));
                        const idx1 = paths.indexOf(lastSelectedPath);
                        const idx2 = paths.indexOf(itemPath);
                        if (idx1 !== -1 && idx2 !== -1) {
                            const start = Math.min(idx1, idx2);
                            const end = Math.max(idx1, idx2);
                            for (let i = start; i <= end; i++) {
                                selectedPaths.add(paths[i]);
                            }
                        }
                    } else {
                        selectedPaths.clear();
                        selectedPaths.add(itemPath);
                    }

                    lastSelectedPath = itemPath;
                    updateSelectionUI();
                });

                tr.addEventListener('dblclick', function(e) {
                    if (e.target.closest('.fm-col-actions') || e.target.classList.contains('fm-item-check')) {
                        return;
                    }

                    if (item.isDir) {
                        loadFolder(itemPath);
                    } else if (item.isEditable) {
                        openEditor(itemPath, item.name);
                    } else if (item.isImage) {
                        openImage(itemPath, item.name);
                    } else {
                        window.location.href = downloadUrl;
                    }
                });

                const btnEdit = tr.querySelector('.fm-btn-edit');
                if (btnEdit) {
                    btnEdit.addEventListener('click', () => openEditor(itemPath, item.name));
                }

                const btnView = tr.querySelector('.fm-btn-view');
                if (btnView) {
                    btnView.addEventListener('click', () => openImage(itemPath, item.name));
                }

                const btnRename = tr.querySelector('.fm-btn-rename');
                if (btnRename) {
                    btnRename.addEventListener('click', () => openRename(itemPath, item.name));
                }

                const btnDelete = tr.querySelector('.fm-btn-delete');
                if (btnDelete) {
                    btnDelete.addEventListener('click', () => deleteItem(itemPath, item.isDir));
                }

                tableBody.appendChild(tr);
            });
        }

        function openRename(path, name) {
            if (!inputRenameOldPath || !inputRenameNewName || !modalRename) return;
            inputRenameOldPath.value = path;
            inputRenameNewName.value = name;
            openModal(modalRename);
            setTimeout(() => {
                inputRenameNewName.focus();
                inputRenameNewName.select();
            }, 50);
        }

        function deleteItem(path, isDir) {
            const type = isDir ? 'folder' : 'file';
            pendingDelete = { type: 'single', path: path };
            if (deleteTitle) {
                deleteTitle.textContent = 'Delete';
            }
            if (deletePrompt) {
                deletePrompt.textContent = `Are you sure you want to delete this ${type}? This action cannot be undone.`;
            }
            openModal(modalDelete);
        }

        function openEditor(path, name) {
            if (!modalEditor) return;
            request('read', { path })
                .then(data => {
                    editorTitle.textContent = `Edit: ${name}`;
                    inputEditorPath.value = path;
                    textareaEditor.value = data.content;
                    openModal(modalEditor);
                })
                .catch(err => alert(err.message));
        }

        function openImage(path, name) {
            if (!modalImage) return;
            imageTitle.textContent = name;
            const dlUrlObj = resolveUrl(config.downloadUrl);
            dlUrlObj.searchParams.set('root', currentRoot);
            dlUrlObj.searchParams.set('path', path);
            imagePreview.src = dlUrlObj.toString();
            openModal(modalImage);
        }

        if (checkAll) {
            checkAll.addEventListener('change', function() {
                if (this.checked) {
                    selectAllItems();
                } else {
                    unselectAllItems();
                }
            });
        }

        if (btnSelectAll) {
            btnSelectAll.addEventListener('click', selectAllItems);
        }

        if (btnUnselectAll) {
            btnUnselectAll.addEventListener('click', unselectAllItems);
        }

        if (btnCopy) {
            btnCopy.addEventListener('click', function() {
                if (selectedPaths.size === 0) {
                    alert('Please select at least one file or folder to copy.');
                    return;
                }
                inputCopyDestination.value = currentPath;
                openModal(modalCopy);
                setTimeout(() => {
                    inputCopyDestination.focus();
                    inputCopyDestination.select();
                }, 50);
            });
        }

        if (btnSubmitCopy) {
            btnSubmitCopy.addEventListener('click', function() {
                const destination = inputCopyDestination.value.trim();
                const items = Array.from(selectedPaths);
                if (items.length === 0) return;

                request('copy', { destination, items: JSON.stringify(items) }, 'POST')
                    .then(() => {
                        closeModal(modalCopy);
                        loadFolder(currentPath);
                    })
                    .catch(err => alert(err.message));
            });
        }

        if (btnMove) {
            btnMove.addEventListener('click', function() {
                if (selectedPaths.size === 0) {
                    alert('Please select at least one file or folder to move.');
                    return;
                }
                inputMoveDestination.value = currentPath;
                openModal(modalMove);
                setTimeout(() => {
                    inputMoveDestination.focus();
                    inputMoveDestination.select();
                }, 50);
            });
        }

        if (btnSubmitMove) {
            btnSubmitMove.addEventListener('click', function() {
                const destination = inputMoveDestination.value.trim();
                const items = Array.from(selectedPaths);
                if (items.length === 0) return;

                request('move', { destination, items: JSON.stringify(items) }, 'POST')
                    .then(() => {
                        closeModal(modalMove);
                        loadFolder(currentPath);
                    })
                    .catch(err => alert(err.message));
            });
        }

        if (btnDeleteSelected) {
            btnDeleteSelected.addEventListener('click', function() {
                if (selectedPaths.size === 0) {
                    alert('Please select at least one file or folder to delete.');
                    return;
                }
                const items = Array.from(selectedPaths);
                pendingDelete = { type: 'bulk', items: items };
                if (deleteTitle) {
                    deleteTitle.textContent = 'Delete';
                }
                if (deletePrompt) {
                    deletePrompt.textContent = `Are you sure you want to delete ${items.length} selected item(s)? This action cannot be undone.`;
                }
                openModal(modalDelete);
            });
        }

        if (btnSubmitDelete) {
            btnSubmitDelete.addEventListener('click', function() {
                if (!pendingDelete) return;

                let promise;
                if (pendingDelete.type === 'single') {
                    const pathToDelete = pendingDelete.path;
                    promise = request('delete', { path: pathToDelete }, 'POST')
                        .then(() => {
                            selectedPaths.delete(pathToDelete);
                        });
                } else if (pendingDelete.type === 'bulk') {
                    const itemsToDelete = pendingDelete.items;
                    promise = request('delete', { items: JSON.stringify(itemsToDelete) }, 'POST')
                        .then(() => {
                            selectedPaths.clear();
                        });
                }

                if (promise) {
                    promise
                        .then(() => {
                            closeModal(modalDelete);
                            pendingDelete = null;
                            loadFolder(currentPath);
                        })
                        .catch(err => alert(err.message));
                }
            });
        }

        if (btnSubmitFolder) {
            btnSubmitFolder.addEventListener('click', () => {
                const name = inputFolderName.value.trim();
                if (!name) return;

                request('mkdir', { path: currentPath, folderName: name }, 'POST')
                    .then(() => {
                        closeModal(modalFolder);
                        inputFolderName.value = '';
                        loadFolder(currentPath);
                    })
                    .catch(err => alert(err.message));
            });
        }

        if (inputFolderName) {
            inputFolderName.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (btnSubmitFolder) btnSubmitFolder.click();
                }
            });
        }

        if (btnSubmitFile) {
            btnSubmitFile.addEventListener('click', () => {
                const name = inputFileName.value.trim();
                if (!name) return;

                request('createFile', { path: currentPath, fileName: name }, 'POST')
                    .then(() => {
                        closeModal(modalFile);
                        inputFileName.value = '';
                        loadFolder(currentPath);
                    })
                    .catch(err => alert(err.message));
            });
        }

        if (inputFileName) {
            inputFileName.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (btnSubmitFile) btnSubmitFile.click();
                }
            });
        }

        if (btnSubmitRename) {
            btnSubmitRename.addEventListener('click', () => {
                const oldPath = inputRenameOldPath.value;
                const newName = inputRenameNewName.value.trim();
                if (!newName) return;

                request('rename', { path: oldPath, newName }, 'POST')
                    .then(() => {
                        closeModal(modalRename);
                        loadFolder(currentPath);
                    })
                    .catch(err => alert(err.message));
            });
        }

        if (inputRenameNewName) {
            inputRenameNewName.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (btnSubmitRename) btnSubmitRename.click();
                }
            });
        }

        if (inputCopyDestination) {
            inputCopyDestination.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (btnSubmitCopy) btnSubmitCopy.click();
                }
            });
        }

        if (inputMoveDestination) {
            inputMoveDestination.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (btnSubmitMove) btnSubmitMove.click();
                }
            });
        }

        if (btnSubmitSaveFile) {
            btnSubmitSaveFile.addEventListener('click', () => {
                const path = inputEditorPath.value;
                const content = textareaEditor.value;

                request('write', { path, content }, 'POST')
                    .then(() => {
                        closeModal(modalEditor);
                        loadFolder(currentPath);
                    })
                    .catch(err => alert(err.message));
            });
        }

        function uploadFiles(files) {
            if (!files || files.length === 0) return;

            let queue = Array.from(files);
            let done = 0;

            function processNext() {
                if (queue.length === 0) {
                    loadFolder(currentPath);
                    return;
                }

                const file = queue.shift();
                const formData = new FormData();
                formData.append('csrfToken', config.csrfToken);
                formData.append('root', currentRoot);
                formData.append('path', currentPath);
                formData.append('file', file);

                const url = resolveUrl(config.apiUrl);
                url.searchParams.set('action', 'upload');

                fetch(url.toString(), {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(async res => {
                    const text = await res.text();
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        throw new Error('Server returned invalid response');
                    }
                    if (!data.status) {
                        alert(`Failed to upload ${file.name}: ${data.content || 'Error'}`);
                    }
                    done++;
                    processNext();
                })
                .catch(err => {
                    alert(`Error uploading ${file.name}: ${err.message}`);
                    done++;
                    processNext();
                });
            }

            processNext();
        }

        if (fileInput) {
            fileInput.addEventListener('change', () => {
                uploadFiles(fileInput.files);
                fileInput.value = '';
            });
        }

        if (btnNewFolder) {
            btnNewFolder.addEventListener('click', () => {
                inputFolderName.value = '';
                openModal(modalFolder);
                inputFolderName.focus();
            });
        }

        if (btnNewFile) {
            btnNewFile.addEventListener('click', () => {
                inputFileName.value = '';
                openModal(modalFile);
                inputFileName.focus();
            });
        }

        if (btnRefresh) {
            btnRefresh.addEventListener('click', () => {
                loadFolder(currentPath);
            });
        }

        function performDeepSearch() {
            if (!searchInput) return;
            const q = searchInput.value.trim();
            if (!q) {
                isSearchingDeep = false;
                loadFolder(currentPath);
                return;
            }

            showLoading();
            isSearchingDeep = true;
            request('search', { path: currentPath, query: q })
                .then(data => {
                    renderBreadcrumbs(currentPath, q);
                    renderTable(data.items, true);
                })
                .catch(err => {
                    console.error('[FileManager] Search error:', err);
                    tableBody.innerHTML = `<tr><td colspan="5" class="fm-empty text-danger" style="color:#d9534f; padding: 20px; text-align: center;">Search error: ${escapeHtml(err.message)}</td></tr>`;
                });
        }

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                if (isSearchingDeep) {
                    if (!this.value.trim()) {
                        isSearchingDeep = false;
                        loadFolder(currentPath);
                    }
                    return;
                }
                const q = this.value.trim().toLowerCase();
                if (!q) {
                    renderTable(allCurrentItems);
                    return;
                }
                const filtered = allCurrentItems.filter(item => item.name.toLowerCase().includes(q));
                if (filtered.length === 0) {
                    tableBody.innerHTML = `<tr><td colspan="5" class="fm-empty">No items match "${escapeHtml(q)}". Press Enter to search subfolders.</td></tr>`;
                    selectedPaths.clear();
                    updateSelectionUI();
                } else {
                    renderTable(filtered);
                }
            });

            searchInput.addEventListener('search', function() {
                if (!this.value) {
                    isSearchingDeep = false;
                    renderBreadcrumbs(currentPath);
                    renderTable(allCurrentItems);
                }
            });

            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    performDeepSearch();
                }
            });
        }

        const btnSearch = document.getElementById('fmBtnSearch');
        if (btnSearch) {
            btnSearch.addEventListener('click', performDeepSearch);
        }

        if (rootSelect) {
            rootSelect.addEventListener('change', () => {
                currentRoot = rootSelect.value;
                loadFolder('');
            });
        }

        if (dropzone) {
            let dragCounter = 0;

            dropzone.addEventListener('dragenter', (e) => {
                e.preventDefault();
                dragCounter++;
                dropzone.classList.add('fm-dragover');
            });

            dropzone.addEventListener('dragover', (e) => {
                e.preventDefault();
            });

            dropzone.addEventListener('dragleave', (e) => {
                e.preventDefault();
                dragCounter--;
                if (dragCounter <= 0) {
                    dragCounter = 0;
                    dropzone.classList.remove('fm-dragover');
                }
            });

            dropzone.addEventListener('drop', (e) => {
                e.preventDefault();
                dragCounter = 0;
                dropzone.classList.remove('fm-dragover');
                if (e.dataTransfer && e.dataTransfer.files) {
                    uploadFiles(e.dataTransfer.files);
                }
            });
        }

        function escapeHtml(str) {
            return (str || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        loadFolder('');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFileManager);
    } else {
        initFileManager();
    }
})();