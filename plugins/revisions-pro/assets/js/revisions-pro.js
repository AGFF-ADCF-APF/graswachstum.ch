/**
 * Revisions Pro Plugin JavaScript
 */
var RevisionsProPlugin = {

    trashButton: null,
    
    init: function() {
        this.attachEventHandlers();
        this.addToolbarHistoryIcon();
        this.addTrashToolbarButton();
    },
    
    getAdminUrl: function(path) {
        // Get the current URL pathname
        var pathname = window.location.pathname;
        var baseUrl = GravAdmin.config.base_url_relative;
        
        // Find where /admin/ appears in the current URL
        var adminIndex = pathname.indexOf('/admin/');
        if (adminIndex !== -1) {
            // Extract everything up to and including /admin/
            var adminPath = pathname.substring(0, adminIndex + 7); // +7 for "/admin/"
            return adminPath + path;
        }
        
        // Fallback to original logic if /admin/ not found in current URL
        var adminRoute = document.documentElement.getAttribute('data-admin-route') || '/admin';
        adminRoute = adminRoute.replace(/^\//, '');
        return baseUrl + '/' + adminRoute + '/' + path;
    },
    
    addToolbarHistoryIcon: function() {
        // Add history icon to the main admin toolbar (next to Save button)
        var titlebar = $('#titlebar');
        var buttonBar = titlebar.find('.button-bar');
        
        if (buttonBar.length && !buttonBar.find('.revisions-history-button').length) {
            // Check if we're on a page that can have revisions and if tracking is enabled
            var pathname = window.location.pathname;
            var shouldShowButton = false;
            
            // Get configuration, fallback to defaults if not available
            var config = window.RevisionsProConfig || { trackPages: true, trackConfig: true, trackPlugins: true };
            
            if (pathname.match(/\/pages\//) && config.trackPages) {
                shouldShowButton = true;
            } else if (pathname.match(/\/config\//) && config.trackConfig) {
                shouldShowButton = true;
            } else if (pathname.match(/\/plugins\//) && config.trackPlugins) {
                shouldShowButton = true;
            } else if (pathname.match(/\/themes\//) && config.trackPlugins) {
                // Themes use the same setting as plugins
                shouldShowButton = true;
            }
            
            if (shouldShowButton) {
                var historyButton = $(
                    '<a class="button revisions-history-button" title="View revision history">' +
                    '<i class="fa fa-history"></i>' +
                    '<span class="revision-count-badge" style="display:none;"></span>' +
                    '</a>'
                );
                
                historyButton.on('click', function(e) {
                    e.preventDefault();
                    RevisionsProPlugin.showRevisionsPanel();
                });
                
                // Find the save button/container and insert before it
                var saveContainer = buttonBar.find('#titlebar-save');
                if (saveContainer.length) {
                    // Pages: Insert before the save button group
                    historyButton.insertBefore(saveContainer);
                } else {
                    // Configuration: Find the save button directly
                    var saveButton = buttonBar.find('button[name="task"][value="save"]');
                    if (saveButton.length) {
                        historyButton.insertBefore(saveButton);
                    } else {
                        // Fallback: Insert before the last child
                        var children = buttonBar.children();
                        if (children.length > 0) {
                            historyButton.insertBefore(children.last());
                        } else {
                            buttonBar.append(historyButton);
                        }
                    }
                }
                
                // Update revision count if enabled
                if (window.RevisionsProConfig && window.RevisionsProConfig.showRevisionCount) {
                    this.updateRevisionCount(historyButton);
                }
            }
        }
    },
    
    updateRevisionCount: function(button) {
        var self = this;
        var route = this.getCurrentRoute();
        var type = this.getCurrentType();
        
        
        if (!route) {
            return;
        }
        
        $.ajax({
            url: self.getAdminUrl('revisions-api'),
            data: {
                action: 'list',
                route: route,
                type: type
            },
            success: function(response) {
                
                // Parse the response to count revisions
                var tempDiv = $('<div>').html(response);
                
                // Try different selectors
                var revisionBlocks = tempDiv.find('.revision-block');
                var revisionRows = tempDiv.find('.revision-row');
                var revisionItems = tempDiv.find('.revision-item');
                
                var count = Math.max(revisionBlocks.length, revisionRows.length, revisionItems.length);
                
                
                if (count > 0) {
                    var badge = button.find('.revision-count-badge');
                    badge.text(count);
                    badge.css('display', 'block');
                }
            },
            error: function(xhr, status, error) {
            }
        });
    },

    addTrashToolbarButton: function() {
        if (!window.RevisionsProConfig || !window.RevisionsProConfig.trashEnabled) {
            return;
        }

        var pathname = window.location.pathname;
        if (!pathname.match(/\/pages(\/|$)/)) {
            return;
        }

        var buttonBar = $('#titlebar .button-bar');
        if (!buttonBar.length) {
            return;
        }

        if (buttonBar.find('.revisions-trash-button').length) {
            this.trashButton = buttonBar.find('.revisions-trash-button');
            return;
        }

        var initialCount = window.RevisionsProConfig.trashCount || 0;
        var badge = $('<span class="trash-count-badge"></span>');
        if (initialCount > 0) {
            badge.text(initialCount);
        } else {
            badge.hide();
        }

        var trashButton = $(
            '<a class="button revisions-trash-button" title="View trashed pages">' +
            '<i class="fa fa-trash"></i>' +
            '</a>'
        );

        trashButton.append(badge);

        var insertBefore = buttonBar.find('.button-group').first();
        if (insertBefore.length) {
            trashButton.insertBefore(insertBefore);
        } else {
            buttonBar.append(trashButton);
        }

        trashButton.on('click', function(event) {
            event.preventDefault();
            RevisionsProPlugin.showTrashPanel();
        });

        this.trashButton = trashButton;
        this.updateTrashCountBadge(initialCount);
    },

    updateTrashCountBadge: function(count) {
        if (!this.trashButton) {
            return;
        }

        var badge = this.trashButton.find('.trash-count-badge');
        if (count > 0) {
            badge.text(count).show();
        } else {
            badge.hide();
        }

        window.RevisionsProConfig.trashCount = count;
    },

    ensureTrashPanel: function() {
        var panel = $('#revisions-trash-panel');
        if (panel.length) {
            return panel;
        }

        var overlay = $('<div id="revisions-trash-overlay" class="revisions-overlay"></div>');
        panel = $(
            '<div id="revisions-trash-panel" class="revisions-panel revisions-trash-panel">' +
            '<div class="revisions-panel-header">' +
            '<h3><i class="fa fa-trash"></i> Page Trash</h3>' +
            '<button type="button" class="close-trash-panel" title="Close"><i class="fa fa-times"></i></button>' +
            '</div>' +
            '<div class="revisions-panel-content">' +
            '<div id="revisions-trash-container">' +
            '<p class="loading"><i class="fa fa-spinner fa-spin"></i> Loading trashed pages...</p>' +
            '</div>' +
            '</div>' +
            '<div class="revisions-panel-footer">' +
            '<button type="button" class="button button-danger revisions-trash-empty">Empty Trash</button>' +
            '</div>' +
            '</div>'
        );

        $('body').append(overlay).append(panel);

        overlay.on('click', function() {
            RevisionsProPlugin.closeTrashPanel();
        });

        panel.find('.close-trash-panel').on('click', function() {
            RevisionsProPlugin.closeTrashPanel();
        });

        panel.on('click', '.revisions-trash-empty', function(event) {
            event.preventDefault();
            RevisionsProPlugin.emptyTrash();
        });

        $('#revisions-trash-container').on('click', '.revisions-trash-restore', function(event) {
            event.preventDefault();
            var item = $(this).closest('.trash-item');
            RevisionsProPlugin.showTrashRestoreDialog(item);
        });

        $('#revisions-trash-container').on('click', '.revisions-trash-delete', function(event) {
            event.preventDefault();
            var item = $(this).closest('.trash-item');
            RevisionsProPlugin.deleteTrashItem(item);
        });

        return panel;
    },

    showTrashPanel: function() {
        var panel = this.ensureTrashPanel();
        $('#revisions-trash-overlay').addClass('active');
        panel.addClass('active');
        $('body').addClass('revisions-panel-open');

        this.loadTrashItems();
        this.refreshTrashCount();

        $(document).on('keyup.trashpanel', function(e) {
            if (e.keyCode === 27) {
                RevisionsProPlugin.closeTrashPanel();
            }
        });
    },

    closeTrashPanel: function() {
        $('#revisions-trash-overlay').removeClass('active');
        $('#revisions-trash-panel').removeClass('active');
        $('body').removeClass('revisions-panel-open');
        $(document).off('keyup.trashpanel');
    },

    loadTrashItems: function() {
        var container = $('#revisions-trash-container');
        container.html('<p class="loading"><i class="fa fa-spinner fa-spin"></i> Loading trashed pages...</p>');

        $.ajax({
            url: this.getAdminUrl('revisions-api'),
            data: {
                action: 'trash-list'
            },
            success: function(response) {
                container.html(response);
            },
            error: function() {
                container.html('<p class="error">Failed to load trashed pages.</p>');
            }
        });
    },

    getTrashItemData: function(item) {
        if (!item || !item.length) {
            return null;
        }

        return {
            id: item.data('id'),
            title: item.data('title'),
            route: item.data('route'),
            relativePath: item.data('relative-path'),
            folder: item.data('folder'),
            slug: item.data('slug'),
            parentRoute: item.data('parent-route'),
            deletedAt: item.data('deleted-at'),
            deletedBy: item.data('deleted-by'),
            language: item.data('language')
        };
    },

    showTrashRestoreDialog: function(item) {
        var data = this.getTrashItemData(item);
        if (!data) {
            return;
        }

        $('#revisions-trash-restore-dialog').remove();

        var dialog = $(
            '<div id="revisions-trash-restore-dialog" class="revisions-confirm-overlay">' +
            '<div class="revisions-confirm-box revisions-trash-restore-box">' +
            '<div class="revisions-confirm-header">' +
            '<h3><i class="fa fa-trash"></i> Restore "' + data.title + '"</h3>' +
            '</div>' +
            '<div class="revisions-confirm-body">' +
            '<p class="restore-description">Choose where to restore this page.</p>' +
            '<div class="restore-option">' +
            '<label><input type="radio" name="trash-restore-mode" value="original" checked> Restore to original location <code>' + data.route + '</code></label>' +
            '</div>' +
            '<div class="restore-option">' +
            '<label><input type="radio" name="trash-restore-mode" value="custom"> Restore to custom location</label>' +
            '<div class="restore-custom-fields" style="display:none;">' +
            '<label>Parent Route<input type="text" name="trash-parent-route" value="' + (data.parentRoute || '/') + '" placeholder="/parent"></label>' +
            '<label>Slug<input type="text" name="trash-slug" value="' + (data.slug || '') + '" placeholder="my-page"></label>' +
            '<label>Folder Name<input type="text" name="trash-folder" value="' + (data.folder || '') + '" placeholder="01.mypage"></label>' +
            '</div>' +
            '</div>' +
            '<div class="restore-option overwrite-option">' +
            '<label><input type="checkbox" name="trash-overwrite"> Overwrite existing page if one is present</label>' +
            '</div>' +
            '</div>' +
            '<div class="revisions-confirm-footer">' +
            '<button type="button" class="button button-cancel">Cancel</button>' +
            '<button type="button" class="button button-primary button-restore">Restore</button>' +
            '</div>' +
            '</div>' +
            '</div>'
        );

        $('body').append(dialog);

        setTimeout(function() {
            dialog.addClass('active');
        }, 10);

        var slugInput = dialog.find('input[name="trash-slug"]');
        var folderInput = dialog.find('input[name="trash-folder"]');
        var originalFolder = folderInput.val();
        folderInput.data('dirty', false);

        folderInput.on('input', function() {
            folderInput.data('dirty', true);
        });

        slugInput.on('input', function() {
            if (folderInput.data('dirty')) {
                return;
            }

            var slugValue = $(this).val();
            if (originalFolder && /^\d+\./.test(originalFolder)) {
                var prefix = originalFolder.split('.', 1)[0];
                folderInput.val(prefix + '.' + slugValue);
            } else {
                folderInput.val(slugValue);
            }
        });

        dialog.find('input[name="trash-restore-mode"]').on('change', function() {
            var customFields = dialog.find('.restore-custom-fields');
            if ($(this).val() === 'custom') {
                customFields.slideDown(150);
            } else {
                customFields.slideUp(150);
            }
        });

        dialog.find('.button-cancel').on('click', function() {
            dialog.removeClass('active');
            setTimeout(function() {
                dialog.remove();
            }, 250);
        });

        dialog.find('.button-restore').on('click', function() {
            var mode = dialog.find('input[name="trash-restore-mode"]:checked').val();
            var overwrite = dialog.find('input[name="trash-overwrite"]').is(':checked');
            var parentRoute = dialog.find('input[name="trash-parent-route"]').val();
            var slug = dialog.find('input[name="trash-slug"]').val();
            var folder = dialog.find('input[name="trash-folder"]').val();

            RevisionsProPlugin.submitTrashRestore(data.id, {
                mode: mode,
                overwrite: overwrite,
                parent_route: parentRoute,
                slug: slug,
                folder_name: folder
            });

            dialog.removeClass('active');
            setTimeout(function() {
                dialog.remove();
            }, 250);
        });

        $(document).on('keyup.trashrestore', function(e) {
            if (e.keyCode === 27) {
                dialog.find('.button-cancel').click();
                $(document).off('keyup.trashrestore');
            }
        });
    },

    submitTrashRestore: function(id, options) {
        var self = this;

        var parentRoute = (options.parent_route || '').trim();
        var slug = (options.slug || '').trim();
        var folderName = (options.folder_name || '').trim();
        if (!parentRoute) {
            parentRoute = '/';
        }

        $.ajax({
            url: this.getAdminUrl('revisions-api'),
            type: 'POST',
            data: {
                action: 'trash-restore',
                id: id,
                mode: options.mode,
                overwrite: options.overwrite,
                parent_route: parentRoute,
                slug: slug,
                folder_name: folderName,
                custom_route: parentRoute && slug ? (parentRoute === '/' ? '/' + slug : parentRoute.replace(/\/$/, '') + '/' + slug) : null
            },
            success: function(response) {
                if (response && response.success) {
                    self.closeTrashPanel();
                    if (response && typeof response.count !== 'undefined') {
                        self.updateTrashCountBadge(response.count);
                    }
                    window.location.reload();
                } else {
                    var errorMessage = response && response.message ? response.message : 'Failed to restore page from trash.';
                    self.showNotification('Error', errorMessage, 'error');
                }
            },
            error: function(xhr) {
                var message = 'Failed to restore page from trash.';
                if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                    message = xhr.responseJSON.message;
                }
                self.showNotification('Error', message, 'error');
            }
        });
    },

    deleteTrashItem: function(item) {
        var self = this;
        var data = this.getTrashItemData(item);
        if (!data) {
            return;
        }

        this.showConfirmDialog(
            'Delete Permanently',
            'Are you sure you want to permanently remove \'' + data.title + '\' from trash? This cannot be undone.',
            function() {
                $.ajax({
                    url: self.getAdminUrl('revisions-api'),
                    type: 'POST',
                    data: {
                        action: 'trash-delete',
                        id: data.id
                    },
                    success: function(response) {
                        if (response && response.success) {
                            self.loadTrashItems();
                            if (typeof response.count !== 'undefined') {
                                self.updateTrashCountBadge(response.count);
                            } else {
                                self.refreshTrashCount();
                            }
                            self.showNotification('Deleted', response.message || 'Page removed from trash.', 'success');
                        } else {
                            self.showNotification('Error', (response && response.message) || 'Failed to remove page from trash.', 'error');
                        }
                    },
                    error: function(xhr) {
                        var message = 'Failed to remove page from trash.';
                        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                            message = xhr.responseJSON.message;
                        }
                        self.showNotification('Error', message, 'error');
                    }
                });
            }
        );
    },

    emptyTrash: function() {
        var self = this;

        this.showConfirmDialog(
            'Empty Trash',
            'This will permanently delete all items from trash. Continue?',
            function() {
                $.ajax({
                    url: self.getAdminUrl('revisions-api'),
                    type: 'POST',
                    data: {
                        action: 'trash-empty'
                    },
                    success: function(response) {
                        if (response && response.success) {
                            self.loadTrashItems();
                            self.updateTrashCountBadge(0);
                            self.showNotification('Trash Emptied', response.message || 'Trash has been emptied.', 'success');
                        } else {
                            self.showNotification('Error', (response && response.message) || 'Failed to empty trash.', 'error');
                        }
                    },
                    error: function(xhr) {
                        var message = 'Failed to empty trash.';
                        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                            message = xhr.responseJSON.message;
                        }
                        self.showNotification('Error', message, 'error');
                    }
                });
            }
        );
    },

    refreshTrashCount: function() {
        var self = this;

        $.ajax({
            url: this.getAdminUrl('revisions-api'),
            data: {
                action: 'trash-count'
            },
            success: function(response) {
                if (response && response.success) {
                    self.updateTrashCountBadge(response.count || 0);
                }
            }
        });
    },
    
    getCurrentRoute: function() {
        var pathname = window.location.pathname;
        
        // For pages, extract the route
        if (pathname.includes('/pages/')) {
            var match = pathname.match(/\/pages\/(.*?)(?:\/mode:|$)/);
            if (match) {
                var route = match[1];
                
                // Check if we're on a language-specific admin URL (e.g., /fr/admin/)
                var langMatch = pathname.match(/\/([a-z]{2})\/admin\//);
                if (langMatch) {
                    var lang = langMatch[1];
                    // Get default language from config
                    var defaultLang = window.RevisionsProConfig && window.RevisionsProConfig.defaultLanguage || '';
                    
                    // Only append language suffix for non-default languages
                    // Default language doesn't use language suffix in storage
                    if (lang !== defaultLang && !route.endsWith(':' + lang)) {
                        route = route + ':' + lang;
                    }
                }
                
                return route;
            }
        }
        
        // For config pages
        if (pathname.includes('/config/')) {
            var match = pathname.match(/\/config\/([^\/]+)/);
            if (match) {
                return match[1];
            }
        }
        
        // For plugin pages
        if (pathname.includes('/plugins/')) {
            var match = pathname.match(/\/plugins\/([^\/]+)/);
            if (match) {
                return match[1];
            }
        }
        
        // For theme pages
        if (pathname.includes('/themes/')) {
            var match = pathname.match(/\/themes\/([^\/]+)/);
            if (match) {
                return match[1];
            }
        }
        
        return null;
    },
    
    getCurrentType: function() {
        var pathname = window.location.pathname;
        
        if (pathname.includes('/pages/')) {
            return 'page';
        } else if (pathname.includes('/plugins/')) {
            return 'plugin-config';
        } else if (pathname.includes('/themes/')) {
            return 'theme-config';
        } else if (pathname.includes('/config/')) {
            // Extract the config name dynamically
            var match = pathname.match(/\/config\/([^\/]+)/);
            if (match && match[1]) {
                return 'config-' + match[1];
            }
            return 'config-site'; // fallback
        }
        
        return 'page';
    },
    
    updateRevisionCountAfterDelete: function() {
        // Update the count badge
        var badge = $('.revision-count-badge');
        if (badge.length) {
            var currentCount = parseInt(badge.text()) || 0;
            var newCount = currentCount - 1;
            
            if (newCount > 0) {
                badge.text(newCount);
            } else {
                badge.hide();
            }
        }
        
        // Update revision numbers on remaining blocks
        $('.revision-block').each(function(index) {
            var revisionNumber = $(this).find('.revision-number');
            if (revisionNumber.length) {
                // Count backwards from total
                var totalBlocks = $('.revision-block').length;
                revisionNumber.text(totalBlocks - index);
            }
        });
    },
    
    checkCurrentRevisionChanges: function() {
        var self = this;
        var compareButton = $('.button.compare-current');
        
        if (compareButton.length === 0) return;
        
        var revisionId = compareButton.data('revision-id');
        
        
        // Make a quick AJAX call to check if there are differences
        $.ajax({
            url: self.getAdminUrl('revisions-api'),
            data: {
                action: 'diff',
                id: revisionId
            },
            success: function(response) {
                // Check if the response contains any diff content
                var hasDiff = false;
                
                if (typeof response === 'string') {
                    // Create a temporary div to parse the HTML response
                    var tempDiv = $('<div>').html(response);
                    
                    // Check for no-changes indicator
                    var hasNoChanges = tempDiv.find('.no-changes-modern').length > 0;
                    
                    // Check for actual diff content inside the diff viewer
                    // Look for diff lines, not the legend
                    var diffViewer = tempDiv.find('.revision-diff-viewer');
                    var hasDiffAdded = diffViewer.find('.diff-line.diff-added').length > 0;
                    var hasDiffRemoved = diffViewer.find('.diff-line.diff-removed').length > 0;
                    
                    // Also check if the unified diff has content (excluding header lines)
                    var unifiedDiff = tempDiv.find('.language-diff code').text();
                    var hasUnifiedDiffContent = false;
                    if (unifiedDiff) {
                        // Check if there are actual + or - lines (not just @@ headers)
                        var lines = unifiedDiff.split('\n');
                        hasUnifiedDiffContent = lines.some(function(line) {
                            return line.startsWith('+') || line.startsWith('-');
                        });
                    }
                    
                    hasDiff = !hasNoChanges && (hasDiffAdded || hasDiffRemoved || hasUnifiedDiffContent);
                    
                }
                
                if (hasDiff) {
                    compareButton.addClass('has-changes');
                    compareButton.attr('title', 'Compare with current version (changes detected)');
                } else {
                    compareButton.attr('title', 'No changes detected');
                    // Prevent click events
                    compareButton.on('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        return false;
                    });
                }
            },
            error: function(xhr, status, error) {
            }
        });
    },
    
    initPageEditor: function() {
        // Add a revisions button to the page actions
        var pageActions = $('.page-actions');
        
        // Get configuration, fallback to defaults if not available
        var config = window.RevisionsProConfig || { trackPages: true, trackConfig: true, trackPlugins: true };
        
        // Only add button if page tracking is enabled
        if (pageActions.length && !pageActions.find('.revision-action-button').length && config.trackPages) {
            var revisionsButton = $(
                '<button type="button" class="button revision-action-button" title="View revision history">' +
                '<i class="fa fa-history"></i> Revisions' +
                '</button>'
            );
            
            revisionsButton.on('click', function(e) {
                e.preventDefault();
                RevisionsProPlugin.showRevisionsPanel();
            });
            
            // Insert before the dropdown button
            var dropdown = pageActions.find('.dropdown');
            if (dropdown.length) {
                revisionsButton.insertBefore(dropdown);
            } else {
                pageActions.append(revisionsButton);
            }
        }
    },
    
    showRevisionsPanel: function() {
        // Create or show the primary revisions panel
        var panel = $('#revisions-panel');
        var overlay = $('#revisions-overlay');
        
        // If panel already exists and is active, just return
        if (panel.length && panel.hasClass('active')) {
            return;
        }
        
        if (!panel.length) {
            // Create overlay
            overlay = $('<div id="revisions-overlay" class="revisions-overlay"></div>');
            $('body').append(overlay);
            
            // Create primary panel
            panel = $(
                '<div id="revisions-panel" class="revisions-panel">' +
                '<div class="revisions-panel-header">' +
                '<h3><i class="fa fa-history"></i> Revision History</h3>' +
                '<button type="button" class="close-panel" title="Close"><i class="fa fa-times"></i></button>' +
                '</div>' +
                '<div class="revisions-panel-content">' +
                '<div id="page-revision-list-container">' +
                '<p class="loading"><i class="fa fa-spinner fa-spin"></i> Loading revisions...</p>' +
                '</div>' +
                '</div>' +
                '</div>'
            );
            
            // Create secondary panel for preview/diff
            var secondaryPanel = $(
                '<div id="revisions-detail-panel" class="revisions-detail-panel">' +
                '<div class="revisions-panel-header">' +
                '<h3 class="panel-title">Revision Details</h3>' +
                '<button type="button" class="close-detail-panel" title="Close"><i class="fa fa-times"></i></button>' +
                '</div>' +
                '<div class="revisions-panel-content">' +
                '<div id="revision-detail-container">' +
                '<p class="panel-instructions">Select a revision to preview or compare</p>' +
                '</div>' +
                '</div>' +
                '</div>'
            );
            
            $('body').append(panel);
            $('body').append(secondaryPanel);
            
            // Close button handlers
            panel.find('.close-panel').on('click', function() {
                RevisionsProPlugin.closePanels();
            });
            
            secondaryPanel.find('.close-detail-panel').on('click', function() {
                secondaryPanel.removeClass('active');
                // On mobile, show the main panel again
                if (RevisionsProPlugin.isMobile()) {
                    $('#revisions-panel').removeClass('mobile-hidden');
                }
            });
            
            // Overlay click to close
            overlay.on('click', function() {
                RevisionsProPlugin.closePanels();
            });
            
            // ESC key to close
            $(document).on('keyup.revisions', function(e) {
                if (e.keyCode === 27) {
                    RevisionsProPlugin.closePanels();
                }
            });
            
            // Load HTMX if not already loaded
            if (typeof htmx === 'undefined') {
                var script = document.createElement('script');
                script.src = 'https://unpkg.com/htmx.org@1.9.10';
                script.onload = function() {
                    // HTMX is now loaded
                };
                document.head.appendChild(script);
            }
        }
        
        // Show overlay and panel
        $('#revisions-overlay').addClass('active');
        $('#revisions-panel').addClass('active');
        $('body').addClass('revisions-panel-open');
        
        // Always load fresh revisions when panel opens
        this.loadPageRevisions();
    },
    
    closePanels: function() {
        $('#revisions-overlay').removeClass('active');
        $('#revisions-panel').removeClass('active mobile-hidden');
        $('#revisions-detail-panel').removeClass('active');
        $('body').removeClass('revisions-panel-open');
        $(document).off('keyup.revisions');
    },
    
    showConfirmDialog: function(title, message, onConfirm) {
        // Remove any existing dialog
        $('#revisions-confirm-dialog').remove();
        
        // Create custom confirmation dialog
        var dialog = $(
            '<div id="revisions-confirm-dialog" class="revisions-confirm-overlay">' +
            '<div class="revisions-confirm-box">' +
            '<div class="revisions-confirm-header">' +
            '<h3>' + title + '</h3>' +
            '</div>' +
            '<div class="revisions-confirm-body">' +
            '<p>' + message + '</p>' +
            '</div>' +
            '<div class="revisions-confirm-footer">' +
            '<button class="button button-cancel">Cancel</button>' +
            '<button class="button button-confirm">Confirm</button>' +
            '</div>' +
            '</div>' +
            '</div>'
        );
        
        $('body').append(dialog);
        
        // Show with animation
        setTimeout(function() {
            dialog.addClass('active');
        }, 10);
        
        // Handle button clicks
        dialog.find('.button-cancel').on('click', function() {
            dialog.removeClass('active');
            setTimeout(function() {
                dialog.remove();
            }, 300);
        });
        
        dialog.find('.button-confirm').on('click', function() {
            dialog.removeClass('active');
            setTimeout(function() {
                dialog.remove();
            }, 300);
            if (typeof onConfirm === 'function') {
                onConfirm();
            }
        });
        
        // Close on ESC
        $(document).on('keyup.confirm', function(e) {
            if (e.keyCode === 27) {
                dialog.find('.button-cancel').click();
                $(document).off('keyup.confirm');
            }
        });
    },
    
    showNotification: function(title, message, type) {
        // Remove any existing notification
        $('#revisions-notification').remove();
        
        var iconClass = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        var headerClass = type === 'success' ? 'success' : 'error';
        
        // Create notification dialog
        var notification = $(
            '<div id="revisions-notification" class="revisions-confirm-overlay">' +
            '<div class="revisions-confirm-box">' +
            '<div class="revisions-confirm-header ' + headerClass + '">' +
            '<h3><i class="fa ' + iconClass + '"></i> ' + title + '</h3>' +
            '</div>' +
            '<div class="revisions-confirm-body">' +
            '<p>' + message + '</p>' +
            '</div>' +
            '<div class="revisions-confirm-footer">' +
            '<button class="button button-primary">OK</button>' +
            '</div>' +
            '</div>' +
            '</div>'
        );
        
        $('body').append(notification);
        
        // Show with animation
        setTimeout(function() {
            notification.addClass('active');
        }, 10);
        
        // Handle OK button
        notification.find('.button-primary').on('click', function() {
            notification.removeClass('active');
            setTimeout(function() {
                notification.remove();
            }, 300);
        });
        
        // Auto close after 5 seconds for success
        if (type === 'success') {
            setTimeout(function() {
                notification.find('.button-primary').click();
            }, 5000);
        }
    },
    
    attachEventHandlers: function() {
        // Currently no global event handlers needed
        // All actions are handled via direct onclick attributes in templates
    },
    
    addRevisionIndicator: function(data) {
        // Get configuration, fallback to defaults if not available
        var config = window.RevisionsProConfig || { trackPages: true, trackConfig: true, trackPlugins: true };
        
        // Only show indicator if page tracking is enabled
        if (!config.trackPages) {
            return;
        }
        
        var titleBar = $('.admin-pages .admin-title');
        
        // Check if indicator already exists
        if (titleBar.find('.revision-indicator').length > 0) {
            // Update count if indicator exists
            titleBar.find('.revision-count').text(data.count + ' revisions');
            return;
        }
        
        if (titleBar.length) {
            var indicator = $(
                '<span class="revision-indicator" title="View revision history">' +
                '<i class="fa fa-history"></i>' +
                '<span class="revision-count">' + data.count + ' revisions</span>' +
                '</span>'
            );
            
            indicator.on('click', function() {
                // Find and click the revisions tab
                $('a[href="#tab-revisions"]').click();
            });
            
            titleBar.append(indicator);
        }
    },
    
    loadPageRevisions: function() {
        var self = this;
        var container = $('#page-revision-list-container');
        
        // Prevent multiple simultaneous loads
        if (container.data('loading')) {
            return;
        }
        
        var route = '';
        var type = 'page';
        
        // Determine the type and route based on URL
        var pathname = window.location.pathname;
        
        if (pathname.match(/\/pages\//)) {
            // Page route - keep language suffix (e.g., "typography:fr")
            var pathMatch = pathname.match(/\/pages\/(.+?)(?:\/mode:|$)/);
            if (pathMatch) {
                route = decodeURIComponent(pathMatch[1]);
                type = 'page';
                
                // Check if we're on a language-specific admin URL (e.g., /fr/admin/)
                var langMatch = pathname.match(/\/([a-z]{2})\/admin\//);
                if (langMatch) {
                    var lang = langMatch[1];
                    // Get default language from config
                    var defaultLang = window.RevisionsProConfig && window.RevisionsProConfig.defaultLanguage || '';
                    
                    // Only append language suffix for non-default languages
                    // Default language doesn't use language suffix in storage
                    if (lang !== defaultLang && !route.endsWith(':' + lang)) {
                        route = route + ':' + lang;
                    }
                }
            }
        } else if (pathname.match(/\/config\/([^\/]+)/)) {
            // Configuration pages (system, site, or custom)
            var configMatch = pathname.match(/\/config\/([^\/]+)/);
            if (configMatch) {
                route = configMatch[1];
                type = 'config-' + configMatch[1];
            }
        } else if (pathname.match(/\/plugins\/([^\/]+)/)) {
            // Plugin configuration
            var pluginMatch = pathname.match(/\/plugins\/([^\/]+)/);
            if (pluginMatch) {
                route = pluginMatch[1];
                type = 'plugin-config';
            }
        } else if (pathname.match(/\/themes\/([^\/]+)/)) {
            // Theme configuration
            var themeMatch = pathname.match(/\/themes\/([^\/]+)/);
            if (themeMatch) {
                route = themeMatch[1];
                type = 'theme-config';
            }
        }
        
        if (!route) {
            container.html('<p class="error">Unable to determine route for this content type.</p>');
            return;
        }
        
        container.data('loading', true);
        container.html('<p class="loading"><i class="fa fa-spinner fa-spin"></i> Loading revisions...</p>');
        
        $.ajax({
            url: self.getAdminUrl('revisions-api'),
            data: {
                action: 'list',
                route: route,
                type: type
            },
            success: function(response) {
                container.html(response);
                container.data('loading', false);
                
                // Process any HTMX attributes in the loaded content
                var processHtmx = function() {
                    if (typeof htmx !== 'undefined') {
                        htmx.process(container[0]);
                    } else {
                        // Wait a bit for HTMX to load
                        setTimeout(processHtmx, 100);
                    }
                };
                processHtmx();
                
                // Check if current revision has changes (with delay for content to settle)
                setTimeout(function() {
                    self.checkCurrentRevisionChanges();
                }, 500);
            },
            error: function(xhr, status, error) {
                container.html('<p class="error">Failed to load revisions: ' + error + '</p>');
                container.data('loading', false);
            }
        });
    },
    
    showDetailPanel: function(type, id) {
        var detailPanel = $('#revisions-detail-panel');
        var container = $('#revision-detail-container');
        
        // Check if this is a compare-current button without changes
        var button = $('[data-revision-id="' + id + '"]').filter('.button');
        if (type === 'diff' && button.hasClass('compare-current') && !button.hasClass('has-changes')) {
            // Don't open panel for current revision with no changes
            return;
        }
        
        // Show the detail panel
        detailPanel.addClass('active');
        
        // On mobile, hide the main panel
        if (this.isMobile()) {
            $('#revisions-panel').addClass('mobile-hidden');
        }
        
        // Update title based on type
        var title = type === 'view' 
            ? '<i class="fa fa-eye"></i> Revision Preview' 
            : '<i class="fa fa-exchange"></i> Comparing Changes';
        detailPanel.find('.panel-title').html(title);
        
        // Clear container for HTMX content
        container.html('');
        
        // Mark the selected revision in the list
        $('.revision-block').removeClass('selected');
        $('[data-revision-id="' + id + '"]').addClass('selected');
        
        // For diff mode, check if we need to compare with previous revision
        if (type === 'diff') {
            var url = this.getAdminUrl('revisions-api') + '?action=diff&id=' + id;
            
            // Get revision info
            var $clickedButton = $('button[onclick*="' + id + '"]').filter('.compare-button');
            var revisionIndex = parseInt($clickedButton.attr('data-revision-index'));
            var isFirst = $clickedButton.attr('data-is-current') === 'true';
            var isLast = $clickedButton.attr('data-is-last') === 'true';
            
            // Check for user's saved preference first, then fall back to config
            var compareMode = window.RevisionsProConfig ? window.RevisionsProConfig.compareMode : 'current';
            if (window.localStorage) {
                var savedMode = localStorage.getItem('revisions-pro-compare-mode');
                if (savedMode) {
                    compareMode = savedMode;
                }
            }
            
            // For the newest revision, force "previous" mode since "current" makes no sense
            if (isFirst && compareMode === 'current') {
                compareMode = 'previous';
            }
            
            if (compareMode === 'previous' || compareMode === 'next') {
                var $allRevisionBlocks = $('#page-revision-list-container .revision-block');
                
                if (compareMode === 'previous') {
                    // If this is not the last revision (oldest), we can compare with previous
                    if (!isLast && !isNaN(revisionIndex)) {
                        // Get the next revision block (which is the previous in time)
                        var $nextBlock = $allRevisionBlocks.eq(revisionIndex + 1);
                        if ($nextBlock.length > 0) {
                            var previousRevisionId = $nextBlock.attr('data-revision-id');
                            if (previousRevisionId) {
                                url += '&compare=' + previousRevisionId;
                            }
                        }
                    }
                } else if (compareMode === 'next') {
                    // If this is not the first revision (newest), we can compare with next
                    if (!isFirst && !isNaN(revisionIndex) && revisionIndex > 0) {
                        // Get the previous revision block (which is the next in time)
                        var $prevBlock = $allRevisionBlocks.eq(revisionIndex - 1);
                        if ($prevBlock.length > 0) {
                            var nextRevisionId = $prevBlock.attr('data-revision-id');
                            if (nextRevisionId) {
                                url += '&compare=' + nextRevisionId;
                            }
                        }
                    }
                }
            }
            
            // Load the diff content
            container.html('<p class="loading"><i class="fa fa-spinner fa-spin"></i> Loading diff...</p>');
            
            $.ajax({
                url: url,
                success: function(response) {
                    
                    // Try to extract the displayed date from the response
                    var tempDiv = $('<div>').html(response);
                    var dateSpan = tempDiv.find('.revision-info-item span').filter(function() {
                        return $(this).text().match(/\w+ \d+, \d+ \d+:\d+ [ap]m/i);
                    });
                    if (dateSpan.length) {
                    }
                    
                    container.html(response);
                    // Initialize syntax highlighting if available
                    if (typeof Prism !== 'undefined') {
                        Prism.highlightAll();
                    }
                },
                error: function() {
                    container.html('<p class="error">Failed to load diff</p>');
                }
            });
            
            return;
        }
        
        // For view mode, load the content
        if (type === 'view') {
            var url = this.getAdminUrl('revisions-api') + '?action=view&id=' + id;
            
            container.html('<p class="loading"><i class="fa fa-spinner fa-spin"></i> Loading revision...</p>');
            
            $.ajax({
                url: url,
                success: function(response) {
                    container.html(response);
                    // Initialize syntax highlighting if available
                    if (typeof Prism !== 'undefined') {
                        Prism.highlightAll();
                    }
                },
                error: function() {
                    container.html('<p class="error">Failed to load revision</p>');
                }
            });
        }
    },
    
    // Keep these for backward compatibility if needed
    viewRevision: function(id) {
        this.showDetailPanel('view', id);
    },
    
    showDiff: function(id, compareWith) {
        this.showDetailPanel('diff', id);
    },
    
    restoreRevision: function(id) {
        var self = this;
        
        this.showConfirmDialog(
            'Restore Revision',
            'Are you sure you want to restore this revision? The current version will be backed up.',
            function() {
                // Get the current route based on URL
            var pathname = window.location.pathname;
            var route = '';
            
            if (pathname.match(/\/pages\//)) {
                // Page route - keep language suffix (e.g., "typography:fr")
                var pathMatch = pathname.match(/\/pages\/(.+?)(?:\/mode:|$)/);
                if (pathMatch) {
                    route = decodeURIComponent(pathMatch[1]);
                    
                    // Check if we're on a language-specific admin URL (e.g., /fr/admin/)
                    var langMatch = pathname.match(/\/([a-z]{2})\/admin\//);
                    if (langMatch) {
                        var lang = langMatch[1];
                        // Append language suffix to route if not already present
                        if (!route.endsWith(':' + lang)) {
                            route = route + ':' + lang;
                        }
                    }
                }
            } else if (pathname.match(/\/config\/([^\/]+)/)) {
                // Configuration route
                var configMatch = pathname.match(/\/config\/([^\/]+)/);
                if (configMatch) {
                    route = configMatch[1];
                }
            }
            
            $.ajax({
            url: self.getAdminUrl('revisions-api'),
            data: { 
                action: 'restore',
                id: id,
                route: route
            },
            type: 'POST',
            success: function(response) {
                
                // Check if response indicates success
                if (response && (response.success || response.status === 'success')) {
                    // Just reload the page - the server has set a session message
                    window.location.reload();
                } else {
                    // Response received but indicates failure
                    var errorMsg = response.message || 'Failed to restore revision.';
                    self.showNotification('Error', errorMsg, 'error');
                }
            },
            error: function(xhr, status, error) {
                
                var errorMsg = 'Failed to restore revision.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                }
                
                self.showNotification('Error', errorMsg, 'error');
            }
        });
            }
        );
    },
    
    isMobile: function() {
        return window.innerWidth <= 980;
    },
    
    deleteRevision: function(id) {
        var self = this;
        
        this.showConfirmDialog(
            'Delete Revision',
            'Are you sure you want to delete this revision? This action cannot be undone.',
            function() {
                $.ajax({
            url: self.getAdminUrl('revisions-api'),
            data: { 
                action: 'delete',
                id: id 
            },
            type: 'POST',
            success: function(response) {
                // Remove the block from the list
                $('[data-revision-id="' + id + '"]').fadeOut(300, function() {
                    $(this).remove();
                    
                    // Update the revision count badge
                    self.updateRevisionCountAfterDelete();
                    
                    // Check if no more revisions
                    if ($('.revision-block').length === 0) {
                        $('#page-revision-list-container').html(
                            '<div class="no-revisions">' +
                            '<i class="fa fa-history fa-3x"></i>' +
                            '<p>No revisions found.</p>' +
                            '</div>'
                        );
                    }
                });
                
                // Close detail panel if this revision was being viewed
                var detailPanel = $('#revisions-detail-panel');
                if (detailPanel.hasClass('active') && detailPanel.find('[data-revision-id="' + id + '"]').length) {
                    detailPanel.removeClass('active');
                    // On mobile, show the main panel again
                    if (self.isMobile()) {
                        $('#revisions-panel').removeClass('mobile-hidden');
                    }
                }
                
                if (typeof GravAdmin !== 'undefined' && GravAdmin.messages) {
                    GravAdmin.messages.add({
                        level: 'info',
                        message: 'Revision deleted successfully.'
                    });
                }
            },
            error: function() {
                if (typeof GravAdmin !== 'undefined' && GravAdmin.messages) {
                    GravAdmin.messages.add({
                        level: 'error',
                        message: 'Failed to delete revision.'
                    });
                } else {
                    alert('Failed to delete revision.');
                }
            }
        });
            }
        );
    },
    
};

// Initialize when document is ready
$(document).ready(function() {
    RevisionsProPlugin.init();
    
    // Re-initialize when admin page changes (for AJAX navigation)
    $(document).on('grav:pageLoaded', function() {
        RevisionsProPlugin.addToolbarHistoryIcon();
        RevisionsProPlugin.addTrashToolbarButton();
    });
    
    // Handle window resize for responsive panel behavior
    var resizeTimer;
    $(window).on('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            // If switching from mobile to desktop, remove mobile-hidden class
            if (!RevisionsProPlugin.isMobile() && $('#revisions-panel').hasClass('active')) {
                $('#revisions-panel').removeClass('mobile-hidden');
            }
        }, 250);
    });
});
