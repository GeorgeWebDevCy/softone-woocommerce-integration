(function (window, document) {
'use strict';

var config = window.softoneProcessTrace;

if (!config) {
return;
}

document.addEventListener('DOMContentLoaded', function () {
var container = document.querySelector('[data-softone-process-trace]');

if (!container) {
return;
}

initialise(container);
});

function initialise(container) {
var elements = {
container: container,
trigger: container.querySelector('[data-trace-trigger]'),
spinner: container.querySelector('[data-trace-spinner]'),
status: container.querySelector('[data-trace-status]'),
summary: container.querySelector('[data-trace-summary]'),
summaryFields: container.querySelectorAll('[data-trace-summary]'),
emptyState: container.querySelector('[data-trace-empty]'),
entries: container.querySelector('[data-trace-output]'),
options: container.querySelectorAll('[data-trace-option]'),
};

var state = {
running: false,
};

if (elements.trigger) {
elements.trigger.addEventListener('click', function () {
runTrace(elements, state);
});
}
}

function runTrace(elements, state) {
if (!config || !elements.trigger || state.running) {
return;
}

state.running = true;
elements.trigger.disabled = true;
setSpinner(elements.spinner, true);
setStatus(elements.status, config.strings && config.strings.running ? config.strings.running : '');
clearEntries(elements);
toggleSummary(elements.summary, false);

var formData = new window.FormData();
formData.append('action', config.action);
formData.append('nonce', config.nonce);

if (elements.options && elements.options.length) {
elements.options.forEach(function (option) {
if (!option || !option.getAttribute) {
return;
}
var key = option.getAttribute('data-trace-option');
if (!key) {
return;
}
formData.append(key, option.checked ? '1' : '0');
});
}

fetch(config.ajaxUrl, {
method: 'POST',
credentials: 'same-origin',
body: formData,
}).then(function (response) {
return response.json().then(function (payload) {
return {
success: response.ok && payload && payload.success === true,
payload: payload,
};
}).catch(function () {
return {
success: false,
payload: null,
};
});
}).then(function (result) {
if (!result || !result.success) {
var errorPayload = result && result.payload ? result.payload : null;
handleTraceFailure(elements, errorPayload);
return;
}

handleTraceSuccess(elements, result.payload);
}).catch(function (error) {
handleTraceFailure(elements, null, error);
}).finally(function () {
state.running = false;
setSpinner(elements.spinner, false);
elements.trigger.disabled = false;
});
}

function handleTraceSuccess(elements, payload) {
if (!payload || !payload.data) {
handleTraceFailure(elements, payload);
return;
}

var data = payload.data;

if (Array.isArray(data.entries)) {
renderEntries(elements, data.entries);
}

if (data.summary) {
renderSummary(elements.summary, data.summary);
}

if (elements.summary && data.summary) {
toggleSummary(elements.summary, true);
}

var message = config.strings && config.strings.completed ? config.strings.completed : '';
setStatus(elements.status, message, 'success');
}

function handleTraceFailure(elements, payload, error) {
var entries = payload && payload.data && Array.isArray(payload.data.entries) ? payload.data.entries : [];

if (entries.length) {
renderEntries(elements, entries);
}

if (payload && payload.data && payload.data.summary) {
renderSummary(elements.summary, payload.data.summary);
toggleSummary(elements.summary, true);
}

var message = config.strings && config.strings.failed ? config.strings.failed : '';
if (payload && payload.data && payload.data.message) {
message = payload.data.message;
}
if (error && error.message && !message) {
message = error.message;
}

setStatus(elements.status, message, 'error');
}

function clearEntries(elements) {
if (elements.entries) {
elements.entries.innerHTML = '';
}

if (elements.emptyState) {
elements.emptyState.hidden = false;
}
}

function renderEntries(elements, entries) {
if (!elements.entries) {
return;
}

elements.entries.innerHTML = '';

if (!entries || !entries.length) {
if (elements.emptyState) {
elements.emptyState.hidden = false;
}
return;
}

if (elements.emptyState) {
elements.emptyState.hidden = true;
}

entries.forEach(function (entry) {
var item = document.createElement('li');
item.className = 'softone-process-trace__entry';

var type = entry && entry.type ? String(entry.type) : '';
if (type) {
item.className += ' softone-process-trace__entry--' + type.replace(/[^a-z0-9_-]+/gi, '-');
}

var level = entry && entry.level ? String(entry.level).toLowerCase() : 'info';
item.className += ' softone-process-trace__entry--level-' + level.replace(/[^a-z0-9_-]+/gi, '-');

var header = document.createElement('header');
header.className = 'softone-process-trace__entry-header';

var time = document.createElement('time');
time.className = 'softone-process-trace__entry-time';
time.textContent = entry && entry.time ? String(entry.time) : '';
time.dateTime = entry && entry.timestamp ? new Date(entry.timestamp * 1000).toISOString() : '';
header.appendChild(time);

if (type) {
var typeEl = document.createElement('span');
typeEl.className = 'softone-process-trace__entry-type';
typeEl.textContent = type;
header.appendChild(typeEl);
}

var levelEl = document.createElement('span');
levelEl.className = 'softone-process-trace__entry-level';
levelEl.textContent = level.toUpperCase();
header.appendChild(levelEl);

if (entry && entry.action) {
var actionEl = document.createElement('span');
actionEl.className = 'softone-process-trace__entry-action';
actionEl.textContent = entry.action;
header.appendChild(actionEl);
}

item.appendChild(header);

if (entry && entry.message) {
var message = document.createElement('p');
message.className = 'softone-process-trace__entry-message';
message.textContent = entry.message;
item.appendChild(message);
}

if (entry && entry.context && Object.keys(entry.context).length) {
var details = document.createElement('details');
details.className = 'softone-process-trace__entry-context';
var summary = document.createElement('summary');
summary.textContent = config.strings && config.strings.details ? config.strings.details : 'Details';
details.appendChild(summary);

var pre = document.createElement('pre');
pre.textContent = safeStringify(entry.context);
pre.setAttribute('data-trace-context', '');
details.appendChild(pre);

var copyButton = document.createElement('button');
copyButton.type = 'button';
copyButton.className = 'button-link softone-process-trace__copy';
copyButton.textContent = config.strings && config.strings.copyContext ? config.strings.copyContext : 'Copy context';
copyButton.addEventListener('click', function (event) {
event.preventDefault();
copyContext(pre, copyButton);
});
details.appendChild(copyButton);

item.appendChild(details);
}

elements.entries.appendChild(item);
});
}

function renderSummary(summaryContainer, summary) {
if (!summaryContainer) {
return;
}

var statusField = summaryContainer.querySelector('[data-trace-summary="status"]');
var startedField = summaryContainer.querySelector('[data-trace-summary="started_at"]');
var finishedField = summaryContainer.querySelector('[data-trace-summary="finished_at"]');
var durationField = summaryContainer.querySelector('[data-trace-summary="duration"]');
var processedField = summaryContainer.querySelector('[data-trace-summary="processed"]');
var createdField = summaryContainer.querySelector('[data-trace-summary="created"]');
var updatedField = summaryContainer.querySelector('[data-trace-summary="updated"]');
var skippedField = summaryContainer.querySelector('[data-trace-summary="skipped"]');
var staleField = summaryContainer.querySelector('[data-trace-summary="stale_processed"]');

if (statusField) {
var success = !!summary.success;
statusField.textContent = success ? (config.strings && config.strings.successStatus ? config.strings.successStatus : 'Success') : (config.strings && config.strings.failureStatus ? config.strings.failureStatus : 'Failed');
}

if (startedField) {
startedField.textContent = summary.started_at_formatted || '';
}

if (finishedField) {
finishedField.textContent = summary.finished_at_formatted || '';
}

if (durationField) {
var durationText = summary.duration_human || (config.strings && config.strings.durationFallback ? config.strings.durationFallback : '');
if (summary.duration_seconds && summary.duration_seconds > 0) {
durationText += ' (' + summary.duration_seconds + 's)';
}
durationField.textContent = durationText;
}

if (processedField) {
processedField.textContent = formatNumber(summary.processed);
}
if (createdField) {
createdField.textContent = formatNumber(summary.created);
}
if (updatedField) {
updatedField.textContent = formatNumber(summary.updated);
}
if (skippedField) {
skippedField.textContent = formatNumber(summary.skipped);
}
if (staleField) {
staleField.textContent = formatNumber(summary.stale_processed);
}
}

function toggleSummary(summaryContainer, show) {
if (!summaryContainer) {
return;
}

summaryContainer.hidden = !show;
}

function setStatus(element, message, level) {
if (!element) {
return;
}

element.textContent = message || '';
element.className = 'softone-process-trace__status';

if (level) {
element.className += ' softone-process-trace__status--' + level;
}
}

function setSpinner(spinner, active) {
if (!spinner) {
return;
}

if (active) {
spinner.classList.add('is-active');
} else {
spinner.classList.remove('is-active');
}
}

function safeStringify(value) {
try {
return JSON.stringify(value, null, 2);
} catch (error) {
return String(value);
}
}

function copyContext(element, button) {
if (!element) {
return;
}

var text = element.textContent || '';

if (!window.navigator || !window.navigator.clipboard) {
fallbackCopy(text, button);
return;
}

window.navigator.clipboard.writeText(text).then(function () {
showCopyFeedback(button, true);
}).catch(function () {
fallbackCopy(text, button);
});
}

function fallbackCopy(text, button) {
var textarea = document.createElement('textarea');
textarea.value = text;
textarea.setAttribute('readonly', 'readonly');
textarea.style.position = 'absolute';
textarea.style.left = '-9999px';
document.body.appendChild(textarea);
textarea.select();

try {
var succeeded = document.execCommand('copy');
showCopyFeedback(button, succeeded);
} catch (error) {
showCopyFeedback(button, false);
}

document.body.removeChild(textarea);
}

function showCopyFeedback(button, success) {
if (!button) {
return;
}

var original = button.textContent;
button.textContent = success ? (config.strings && config.strings.copied ? config.strings.copied : 'Copied!') : (config.strings && config.strings.copyFailed ? config.strings.copyFailed : 'Copy failed');
window.setTimeout(function () {
button.textContent = original;
}, 2000);
}

function formatNumber(value) {
var number = parseInt(value, 10);
if (isNaN(number)) {
return '0';
}
return number.toLocaleString();
}

})(window, document);
