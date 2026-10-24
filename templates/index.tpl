{extends file="layouts/backend.tpl"}

{block name="breadcrumbs"}{/block}

{block name="page"}
<link rel="stylesheet" href="{$pluginUrl}/assets/css/filemanager.css?v={$fileManagerVersion}">

<div class="fm-container" id="fileManagerApp" v-pre>
    <div class="fm-header">
        <h1 class="app__pageHeading">{$pageTitle}</h1>
        <div class="fm-root-selector">
            <label for="fmRootSelect">Storage:</label>
            <select id="fmRootSelect" class="pkpFormField__input">
                {foreach from=$roots item=r}
                    <option value="{$r.id}">{$r.name}</option>
                {/foreach}
            </select>
        </div>
    </div>

    <div class="fm-breadcrumb-bar">
        <div class="fm-breadcrumbs" id="fmBreadcrumbs">
            <span class="fm-crumb" data-path=""><i class="fa fa-home"></i> Home</span>
        </div>
        <div class="fm-search">
            <input type="search" id="fmSearchInput" class="pkpFormField__input fm-search-input" placeholder="Search..." title="Filter current folder, press Enter for deep search">
            <button type="button" id="fmBtnSearch" class="fm-search-btn" title="Search all subfolders"><span class="fa fa-search"></span></button>
        </div>
    </div>

    <div class="fm-toolbar">
        <div class="fm-actions">
            <button type="button" class="pkpButton" id="fmBtnNewFolder">
                <span class="fa fa-folder"></span> New Folder
            </button>
            <button type="button" class="pkpButton" id="fmBtnNewFile">
                <span class="fa fa-file-text-o"></span> New File
            </button>
            <label class="pkpButton pkpButton--primary fm-upload-label">
                <span class="fa fa-upload"></span> Upload File
                <input type="file" id="fmFileInput" multiple style="display:none;">
            </label>
            <button type="button" class="pkpButton" id="fmBtnCopy" title="Copy Selected">
                <span class="fa fa-copy"></span> Copy
            </button>
            <button type="button" class="pkpButton" id="fmBtnMove" title="Move Selected">
                <span class="fa fa-arrows"></span> Move
            </button>
            <button type="button" class="pkpButton fm-btn-danger" id="fmBtnDeleteSelected" title="Delete Selected">
                <span class="fa fa-trash"></span> Delete
            </button>
            <button type="button" class="pkpButton" id="fmBtnSelectAll" title="Select All">
                <span class="fa fa-check-square-o"></span> Select All
            </button>
            <button type="button" class="pkpButton" id="fmBtnUnselectAll" title="Unselect All">
                <span class="fa fa-square-o"></span> Unselect All
            </button>
            <button type="button" class="pkpButton" id="fmBtnRefresh" title="Refresh">
                <span class="fa fa-refresh"></span>
            </button>
        </div>
    </div>

    <div class="fm-dropzone" id="fmDropzone">
        <div class="fm-table-wrapper">
            <table class="pkpTable fm-table" id="fmTable">
                <thead>
                    <tr>
                        <th class="fm-col-check"><input type="checkbox" id="fmCheckAll" title="Select All"></th>
                        <th class="fm-col-name">Name</th>
                        <th class="fm-col-size">Size</th>
                        <th class="fm-col-date">Modified</th>
                        <th class="fm-col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody id="fmTableBody">
                    <tr><td colspan="5" class="fm-loading">Loading items...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="fm-modal" id="fmModalFolder">
        <div class="fm-modal-content fm-modal-dialog">
            <div class="fm-modal-header">
                <h3>New Folder</h3>
                <button type="button" class="fm-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="fm-modal-body">
                <label for="fmNewFolderName">Folder Name:</label>
                <input type="text" id="fmNewFolderName" class="pkpFormField__input" placeholder="folder-name">
            </div>
            <div class="fm-modal-footer">
                <button type="button" class="pkpButton pkpButton--isPrimary" id="fmSubmitNewFolder">Create</button>
                <button type="button" class="pkpButton fm-modal-cancel">Cancel</button>
            </div>
        </div>
    </div>

    <div class="fm-modal" id="fmModalFile">
        <div class="fm-modal-content fm-modal-dialog">
            <div class="fm-modal-header">
                <h3>New File</h3>
                <button type="button" class="fm-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="fm-modal-body">
                <label for="fmNewFileName">File Name:</label>
                <input type="text" id="fmNewFileName" class="pkpFormField__input" placeholder="e.g. styles.css, robots.txt">
            </div>
            <div class="fm-modal-footer">
                <button type="button" class="pkpButton pkpButton--isPrimary" id="fmSubmitNewFile">Create</button>
                <button type="button" class="pkpButton fm-modal-cancel">Cancel</button>
            </div>
        </div>
    </div>

    <div class="fm-modal" id="fmModalRename">
        <div class="fm-modal-content fm-modal-dialog">
            <div class="fm-modal-header">
                <h3>Rename</h3>
                <button type="button" class="fm-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="fm-modal-body">
                <input type="hidden" id="fmRenameOldPath">
                <input type="text" id="fmRenameNewName" class="pkpFormField__input" aria-label="New name">
            </div>
            <div class="fm-modal-footer">
                <button type="button" class="pkpButton pkpButton--isPrimary" id="fmSubmitRename">Rename</button>
                <button type="button" class="pkpButton fm-modal-cancel">Cancel</button>
            </div>
        </div>
    </div>

    <div class="fm-modal" id="fmModalDelete">
        <div class="fm-modal-content fm-modal-dialog">
            <div class="fm-modal-header">
                <h3 id="fmDeleteTitle">Delete</h3>
                <button type="button" class="fm-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="fm-modal-body">
                <p id="fmDeletePrompt" style="margin: 0;">Are you sure you want to delete this item? This action cannot be undone.</p>
            </div>
            <div class="fm-modal-footer">
                <button type="button" class="pkpButton pkpButton--isWarnable" id="fmSubmitDelete">Delete</button>
                <button type="button" class="pkpButton fm-modal-cancel">Cancel</button>
            </div>
        </div>
    </div>

    <div class="fm-modal" id="fmModalCopy">
        <div class="fm-modal-content fm-modal-dialog">
            <div class="fm-modal-header">
                <h3>Copy</h3>
                <button type="button" class="fm-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="fm-modal-body">
                <label for="fmCopyDestination">Destination folder (leave empty for Home):</label>
                <input type="text" id="fmCopyDestination" class="pkpFormField__input" placeholder="e.g. subfolder">
            </div>
            <div class="fm-modal-footer">
                <button type="button" class="pkpButton pkpButton--isPrimary" id="fmSubmitCopy">Copy</button>
                <button type="button" class="pkpButton fm-modal-cancel">Cancel</button>
            </div>
        </div>
    </div>

    <div class="fm-modal" id="fmModalMove">
        <div class="fm-modal-content fm-modal-dialog">
            <div class="fm-modal-header">
                <h3>Move</h3>
                <button type="button" class="fm-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="fm-modal-body">
                <label for="fmMoveDestination">Destination folder (leave empty for Home):</label>
                <input type="text" id="fmMoveDestination" class="pkpFormField__input" placeholder="e.g. subfolder">
            </div>
            <div class="fm-modal-footer">
                <button type="button" class="pkpButton pkpButton--isPrimary" id="fmSubmitMove">Move</button>
                <button type="button" class="pkpButton fm-modal-cancel">Cancel</button>
            </div>
        </div>
    </div>

    <div class="fm-modal fm-modal--large" id="fmModalEditor">
        <div class="fm-modal-content">
            <div class="fm-modal-header">
                <h3 id="fmEditorTitle">Edit File</h3>
                <button type="button" class="fm-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="fm-modal-body">
                <input type="hidden" id="fmEditorPath">
                <textarea id="fmEditorTextarea" class="fm-editor-textarea" spellcheck="false"></textarea>
            </div>
            <div class="fm-modal-footer">
                <button type="button" class="pkpButton pkpButton--isPrimary" id="fmSubmitSaveFile">Save Changes</button>
                <button type="button" class="pkpButton fm-modal-cancel">Close</button>
            </div>
        </div>
    </div>

    <div class="fm-modal" id="fmModalImage">
        <div class="fm-modal-content fm-modal-content--image">
            <div class="fm-modal-header">
                <h3 id="fmImageTitle">Image Preview</h3>
                <button type="button" class="fm-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="fm-modal-body text-center">
                <img id="fmImagePreview" src="" alt="Preview" style="max-width:100%; max-height:70vh; display:block; margin:0 auto;">
            </div>
            <div class="fm-modal-footer">
                <button type="button" class="pkpButton fm-modal-cancel">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
    window.fmConfig = {
        apiUrl: '{$apiUrl|escape:"javascript"}',
        downloadUrl: '{$downloadUrl|escape:"javascript"}',
        csrfToken: '{$csrfToken|escape:"javascript"}',
        initialRoot: '{$roots[0].id|escape:"javascript"}'
    };
</script>
<script src="{$pluginUrl}/assets/js/filemanager.js?v={$fileManagerVersion}"></script>
{/block}
