(function ( window, document ) {
'use strict';

function addCell( row, text ) {
var cell = document.createElement( 'td' );
cell.textContent = text ? String( text ) : '';
row.appendChild( cell );
}

document.addEventListener( 'DOMContentLoaded', function () {
var data = window.softoneVariableProductLogs;

if ( ! data ) {
return;
}

var container = document.querySelector( '[data-softone-variable-logs]' );

if ( ! container ) {
return;
}

var body = container.querySelector( '[data-logs-body]' );
if ( ! body ) {
return;
}

var emptyRow = container.querySelector( '[data-logs-empty]' );
if ( emptyRow && emptyRow.parentNode ) {
emptyRow.parentNode.removeChild( emptyRow );
}

var pagination = container.querySelector( '[data-logs-pagination]' );
var prevButton = container.querySelector( '[data-logs-prev]' );
var nextButton = container.querySelector( '[data-logs-next]' );
var indicator = container.querySelector( '[data-logs-page-indicator]' );

var entries = Array.isArray( data.entries ) ? data.entries.slice() : [];
var pageSize = parseInt( data.pageSize, 10 );

if ( ! pageSize || pageSize <= 0 ) {
pageSize = 20;
}

var strings = data.strings || {};
var noContextText = strings.noContext || '—';
var noEntriesText = strings.noEntries || '';
var pageIndicatorTemplate = strings.pageIndicator || 'Page %1$d of %2$d';

var currentPage = 1;
var emptyTemplate = emptyRow || null;

function render() {
while ( body.firstChild ) {
body.removeChild( body.firstChild );
}

if ( entries.length === 0 ) {
if ( emptyTemplate ) {
emptyTemplate.hidden = false;
body.appendChild( emptyTemplate );
} else {
var fallbackRow = document.createElement( 'tr' );
var fallbackCell = document.createElement( 'td' );
fallbackCell.colSpan = 5;
fallbackCell.textContent = noEntriesText;
fallbackRow.appendChild( fallbackCell );
body.appendChild( fallbackRow );
}

if ( pagination ) {
pagination.hidden = true;
}

return;
}

if ( emptyTemplate ) {
emptyTemplate.hidden = true;
}

var totalPages = Math.ceil( entries.length / pageSize );

if ( currentPage > totalPages ) {
currentPage = totalPages;
}

if ( currentPage < 1 ) {
currentPage = 1;
}

var start = ( currentPage - 1 ) * pageSize;
var end = Math.min( start + pageSize, entries.length );

for ( var i = start; i < end; i++ ) {
var entry = entries[ i ] || {};
var row = document.createElement( 'tr' );

addCell( row, entry.time );
addCell( row, entry.action );
addCell( row, entry.message );
addCell( row, entry.reason );

var contextCell = document.createElement( 'td' );
contextCell.className = 'softone-variable-logs__context';

if ( entry.context ) {
var pre = document.createElement( 'pre' );
pre.textContent = entry.context;
contextCell.appendChild( pre );
} else {
contextCell.textContent = noContextText;
}

row.appendChild( contextCell );
body.appendChild( row );
}

if ( pagination ) {
pagination.hidden = totalPages <= 1;

if ( indicator ) {
indicator.textContent = pageIndicatorTemplate
.replace( '%1$d', currentPage )
.replace( '%2$d', totalPages );
}

if ( prevButton ) {
prevButton.disabled = currentPage <= 1;
}

if ( nextButton ) {
nextButton.disabled = currentPage >= totalPages;
}
}
}

if ( prevButton ) {
prevButton.addEventListener( 'click', function () {
if ( currentPage > 1 ) {
currentPage--;
render();
}
} );
}

if ( nextButton ) {
nextButton.addEventListener( 'click', function () {
var totalPages = Math.ceil( entries.length / pageSize );

if ( currentPage < totalPages ) {
currentPage++;
render();
}
} );
}

render();
} );
})( window, document );
