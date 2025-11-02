(function (window, document) {
    'use strict';

    var config = window.softoneSyncMonitor;

    if (!config) {
        return;
    }

    var POLL_ERROR_THRESHOLD = 3;
    var pollInterval = parseInt(config.pollInterval, 10) || 0;
    var rowLimit = parseInt(config.limit, 10) || 0;

    document.addEventListener('DOMContentLoaded', function () {
        var container = document.querySelector('[data-softone-sync-monitor]');

        if (!container) {
            return;
        }

        initialise(container);
    });

    function initialise(container) {
        var elements = {
            container: container,
            status: container.querySelector('[data-sync-status]'),
            error: container.querySelector('[data-sync-error]'),
            tableBody: container.querySelector('[data-sync-body]'),
            emptyRow: container.querySelector('.softone-sync-monitor__empty'),
            refreshButton: container.querySelector('[data-sync-refresh]'),
            manualButton: container.querySelector('[data-sync-manual]'),
            metaEntries: container.querySelector('[data-sync-meta="entries"]'),
            metaFile: container.querySelector('[data-sync-meta="file"]'),
            metaSize: container.querySelector('[data-sync-meta="size"]'),
        };

        var state = {
            latestTimestamp: parseInt(config.latestTimestamp, 10) || 0,
            loading: false,
            pollTimer: null,
            errorCount: 0,
            pendingRefresh: null,
            manualSyncInFlight: false,
        };

        if (elements.refreshButton) {
            elements.refreshButton.addEventListener('click', function () {
                fetchEntries(state, elements, { showLoading: true });
            });
        }

        if (elements.manualButton) {
            if (config.manualSync && config.manualSync.enabled !== false) {
                elements.manualButton.addEventListener('click', function () {
                    triggerManualSync(state, elements);
                });
            } else {
                elements.manualButton.disabled = true;
            }
        }

        updateMeta(elements, config.metadata || {});
        renderEntries(elements, config.initialEntries || []);
        toggleEmptyState(elements);

        if (config.error) {
            showError(elements, config.error);
        }

        startPolling(state, elements);
    }

    function startPolling(state, elements) {
        if (pollInterval <= 0) {
            return;
        }

        stopPolling(state);

        state.pollTimer = window.setInterval(function () {
            fetchEntries(state, elements, {});
        }, pollInterval);
    }

    function stopPolling(state) {
        if (state.pollTimer) {
            window.clearInterval(state.pollTimer);
            state.pollTimer = null;
        }
    }

    function fetchEntries(state, elements, options) {
        options = options || {};

        if (state.loading) {
            state.pendingRefresh = options;
            return Promise.resolve();
        }

        if (!config.ajaxUrl || !config.action || !config.nonce) {
            return Promise.resolve();
        }

        state.loading = true;

        if (options.showLoading) {
            setStatus(elements, getString('refreshing'), 'loading');
        }

        clearError(elements);

        var formData = new window.FormData();
        formData.append('action', config.action);
        formData.append('nonce', config.nonce);
        formData.append('since', state.latestTimestamp);

        if (rowLimit > 0) {
            formData.append('limit', rowLimit);
        } else if (config.limit) {
            formData.append('limit', config.limit);
        }

        var requestSucceeded = false;

        return window.fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('http_error');
            }

            return response.json();
        }).then(function (payload) {
            if (!payload || payload.success !== true) {
                var message = payload && payload.data && payload.data.message ? payload.data.message : getString('error');
                throw new Error(message);
            }

            var data = payload.data || {};

            if (Array.isArray(data.entries) && data.entries.length) {
                renderEntries(elements, data.entries);
            }

            if (data.metadata) {
                updateMeta(elements, data.metadata);
            }

            if (typeof data.latestTimestamp !== 'undefined') {
                var latest = parseInt(data.latestTimestamp, 10);
                if (!isNaN(latest) && latest > state.latestTimestamp) {
                    state.latestTimestamp = latest;
                }
            }

            toggleEmptyState(elements);

            state.errorCount = 0;
            clearError(elements);
            requestSucceeded = true;

            if (!state.pollTimer && pollInterval > 0) {
                startPolling(state, elements);
            }
        }).catch(function (error) {
            handleFetchError(state, elements, error);
        }).finally(function () {
            state.loading = false;

            if (state.pendingRefresh) {
                var pending = state.pendingRefresh;
                state.pendingRefresh = null;
                fetchEntries(state, elements, pending);
            }

            if (requestSucceeded && !options.preserveStatus) {
                setStatus(elements, '', '');
            }
        });
    }

    function handleFetchError(state, elements, error) {
        state.errorCount += 1;

        var message = (error && error.message && error.message !== 'http_error') ? error.message : getString('error');

        showError(elements, message);
        setStatus(elements, message, 'error');

        if (state.errorCount >= POLL_ERROR_THRESHOLD) {
            stopPolling(state);
            setStatus(elements, getString('pollingPaused'), 'error');
        }
    }

    function triggerManualSync(state, elements) {
        if (!config.manualSync || config.manualSync.enabled === false || state.manualSyncInFlight) {
            return;
        }

        state.manualSyncInFlight = true;

        if (elements.manualButton) {
            elements.manualButton.disabled = true;
        }

        setStatus(elements, getString('manualSyncStarting'), 'loading');
        clearError(elements);

        var formData = new window.FormData();
        formData.append('action', config.manualSync.action);
        formData.append('_wpnonce', config.manualSync.nonce);

        window.fetch(config.manualSync.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('http_error');
            }

            return response.text();
        }).then(function () {
            setStatus(elements, getString('manualSyncQueued'), 'success');
            fetchEntries(state, elements, { preserveStatus: true });
        }).catch(function () {
            showError(elements, getString('manualSyncError'));
            setStatus(elements, '', 'error');
        }).finally(function () {
            state.manualSyncInFlight = false;

            if (elements.manualButton) {
                elements.manualButton.disabled = false;
            }
        });
    }

    function renderEntries(elements, entries) {
        if (!elements.tableBody) {
            return;
        }

        if (!Array.isArray(entries) || !entries.length) {
            return;
        }

        var fragment = document.createDocumentFragment();

        entries.forEach(function (entry) {
            fragment.appendChild(createRow(entry));
        });

        if (elements.tableBody.firstChild) {
            elements.tableBody.insertBefore(fragment, elements.tableBody.firstChild);
        } else {
            elements.tableBody.appendChild(fragment);
        }

        enforceRowLimit(elements);
    }

    function enforceRowLimit(elements) {
        if (!elements.tableBody || rowLimit <= 0) {
            return;
        }

        var children = elements.tableBody.children;
        var dataRows = [];

        for (var i = 0; i < children.length; i++) {
            var child = children[i];

            if (!child.classList.contains('softone-sync-monitor__empty')) {
                dataRows.push(child);
            }
        }

        while (dataRows.length > rowLimit) {
            var row = dataRows.pop();

            if (row && row.parentNode) {
                row.parentNode.removeChild(row);
            }
        }
    }

    function toggleEmptyState(elements) {
        if (!elements.tableBody || !elements.emptyRow) {
            return;
        }

        var children = elements.tableBody.children;
        var hasData = false;

        for (var i = 0; i < children.length; i++) {
            if (!children[i].classList.contains('softone-sync-monitor__empty')) {
                hasData = true;
                break;
            }
        }

        if (hasData) {
            elements.emptyRow.setAttribute('hidden', 'hidden');
            elements.emptyRow.style.display = 'none';
        } else {
            elements.emptyRow.removeAttribute('hidden');
            elements.emptyRow.style.display = '';
        }
    }

    function createRow(entry) {
        var row = document.createElement('tr');

        appendCell(row, entry.time || '');
        appendCell(row, entry.channel || '');
        appendCell(row, entry.action || '');
        appendCell(row, entry.message || '');

        var contextCell = document.createElement('td');

        if (entry.context_display) {
            var pre = document.createElement('pre');
            pre.textContent = entry.context_display;
            contextCell.appendChild(pre);
        }

        row.appendChild(contextCell);

        return row;
    }

    function appendCell(row, value) {
        var cell = document.createElement('td');
        cell.textContent = value;
        row.appendChild(cell);
    }

    function updateMeta(elements, metadata) {
        if (elements.metaEntries) {
            elements.metaEntries.textContent = getString('entriesDisplayed');
        }

        if (elements.metaFile) {
            if (metadata.file_path) {
                elements.metaFile.textContent = interpolate(getString('logFileLocation'), metadata.file_path);
            } else {
                elements.metaFile.textContent = '';
            }
        }

        if (elements.metaSize) {
            if (metadata.exists) {
                elements.metaSize.textContent = interpolate(getString('logFileSize'), metadata.size_display || '');
            } else {
                elements.metaSize.textContent = getString('logFileMissing');
            }
        }
    }

    function setStatus(elements, message, type) {
        if (!elements.status) {
            return;
        }

        elements.status.textContent = message || '';
        elements.status.classList.remove('is-loading', 'is-error', 'is-success');

        if (type) {
            elements.status.classList.add('is-' + type);
        }

        elements.container.classList.remove('is-loading', 'has-error', 'is-success');

        if (type === 'loading') {
            elements.container.classList.add('is-loading');
        } else if (type === 'error') {
            elements.container.classList.add('has-error');
        } else if (type === 'success') {
            elements.container.classList.add('is-success');
        }
    }

    function showError(elements, message) {
        if (!elements.error) {
            return;
        }

        if (message) {
            elements.error.textContent = message;
            elements.error.removeAttribute('hidden');
        } else {
            elements.error.textContent = '';
            elements.error.setAttribute('hidden', 'hidden');
        }
    }

    function clearError(elements) {
        showError(elements, '');
    }

    function getString(key) {
        if (!config.strings) {
            return '';
        }

        return config.strings[key] || '';
    }

    function interpolate(template, value) {
        if (!template) {
            return value;
        }

        return template.replace('%s', value);
    }

})(window, document);
