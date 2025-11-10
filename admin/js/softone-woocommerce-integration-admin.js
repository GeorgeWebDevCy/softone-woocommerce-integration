(function( $ ) {
        'use strict';

        $( function() {
                if ( 'undefined' === typeof window.softoneApiTester ) {
                        return;
                }

                var config = window.softoneApiTester || {};
                var presets = config.presets || {};
                var $presetField = $( '#softone_api_preset' );

                if ( ! $presetField.length ) {
                        return;
                }

                var $serviceTypeField = $( '#softone_service_type' );
                var $sqlNameField = $( '#softone_sql_name' );
                var $objectField = $( '#softone_object' );
                var $customServiceField = $( '#softone_custom_service' );
                var $requiresClientField = $( '#softone_requires_client_id' );
                var $payloadField = $( '#softone_payload' );
                var $description = $( '#softone_api_preset_description' );
                var defaultDescription = config.defaultDescription || ( $description.data( 'default-description' ) || '' );
                var highlightClass = 'softone-api-field--highlight';

                var highlightTargets = {
                        service_type: $serviceTypeField,
                        sql_name: $sqlNameField,
                        object: $objectField,
                        custom_service: $customServiceField,
                        requires_client_id: $requiresClientField,
                        payload: $payloadField
                };

                var getWrapper = function( $element ) {
                        if ( ! $element || ! $element.length ) {
                                return $();
                        }

                        return $element.closest( '.softone-api-field' );
                };

                var clearHighlights = function() {
                        $( '.' + highlightClass ).removeClass( highlightClass );
                };

                var markPresetFields = function( key ) {
                        clearHighlights();

                        if ( ! key || ! presets[ key ] || ! presets[ key ].form ) {
                                return;
                        }

                        $.each( presets[ key ].form, function( fieldKey, value ) {
                                var $target = highlightTargets[ fieldKey ];

                                if ( 'undefined' === typeof value || ! $target || ! $target.length ) {
                                        return;
                                }

                                var $wrapper = getWrapper( $target );

                                if ( $wrapper.length ) {
                                        $wrapper.addClass( highlightClass );
                                }
                        } );
                };

                var updateDescription = function( key ) {
                        if ( ! $description.length ) {
                                return;
                        }

                        if ( key && presets[ key ] && presets[ key ].description ) {
                                $description.text( presets[ key ].description );
                        } else {
                                $description.text( defaultDescription );
                        }
                };

                var applyPreset = function( key ) {
                        updateDescription( key );
                        markPresetFields( key );

                        if ( ! key || ! presets[ key ] ) {
                                return;
                        }

                        var form = presets[ key ].form || {};

                        if ( form.service_type ) {
                                $serviceTypeField.val( form.service_type );
                        }

                        if ( typeof form.sql_name !== 'undefined' ) {
                                $sqlNameField.val( form.sql_name );
                        }

                        if ( typeof form.object !== 'undefined' ) {
                                $objectField.val( form.object );
                        }

                        if ( typeof form.custom_service !== 'undefined' ) {
                                $customServiceField.val( form.custom_service );
                        }

                        if ( typeof form.requires_client_id !== 'undefined' ) {
                                $requiresClientField.prop( 'checked', !! form.requires_client_id );
                        }

                        if ( typeof form.payload !== 'undefined' ) {
                                $payloadField.val( form.payload );
                        }
                };

                $presetField.on( 'change', function() {
                        applyPreset( $( this ).val() );
                } );

updateDescription( $presetField.val() );
markPresetFields( $presetField.val() );
} );

$( function() {
if ( 'undefined' === typeof window.softoneMenuDeletion ) {
return;
}

var settings = window.softoneMenuDeletion || {};
var formSelector = settings.formSelector || '#softone-delete-main-menu-form';
var $form = $( formSelector );

if ( ! $form.length ) {
return;
}

var ajaxUrl = settings.ajaxUrl || window.ajaxurl || '';

if ( ! ajaxUrl || ! settings.action || ! settings.nonce ) {
return;
}

var $submit = $form.find( 'input[type="submit"], button[type="submit"]' ).first();
var $status = $( '#softone-delete-main-menu-status' );
var $progress = $( '#softone-delete-main-menu-progress' );
var $progressBar = $progress.find( '.softone-delete-menu-progress__bar' );
var $progressFill = $progress.find( '.softone-delete-menu-progress__bar-fill' );
var $progressText = $( '#softone-delete-main-menu-progress-text' );

var state = {
running: false,
processId: '',
total: 0,
deleted: 0
};

var batchSize = parseInt( settings.batchSize, 10 );

if ( ! batchSize || batchSize < 1 ) {
batchSize = 20;
}

var i18n = settings.i18n || {};

var ensureProgressVisible = function() {
if ( $progress.length ) {
$progress.prop( 'hidden', false );
}
};

var resetProgress = function() {
if ( $progressFill.length ) {
$progressFill.css( 'width', '0%' );
}

if ( $progressBar.length ) {
$progressBar.attr( 'aria-valuenow', 0 );
}

if ( $progressText.length ) {
$progressText.text( '' );
}

if ( $progress.length ) {
$progress.prop( 'hidden', true );
}
};

var setProgressPercent = function( percent ) {
if ( $progressFill.length ) {
$progressFill.css( 'width', percent + '%' );
}

if ( $progressBar.length ) {
$progressBar.attr( 'aria-valuenow', percent );
}
};

var setProgressText = function( text ) {
if ( $progressText.length ) {
$progressText.text( text || '' );
}
};

var showStatus = function( message, type ) {
if ( ! $status.length ) {
return;
}

$status.removeClass( 'notice-success notice-error notice-info' );

if ( ! message ) {
$status.prop( 'hidden', true );
return;
}

var className = 'notice';

if ( 'success' === type || 'error' === type ) {
className += ' notice-' + type;
} else {
className += ' notice-info';
}

$status.addClass( className ).text( message ).prop( 'hidden', false );
};

var setButtonBusy = function( busy ) {
if ( ! $submit.length ) {
return;
}

$submit.prop( 'disabled', !! busy );

if ( busy ) {
$submit.attr( 'aria-busy', 'true' );
} else {
$submit.removeAttr( 'aria-busy' );
}
};

var formatProgress = function( deleted, total ) {
var template = i18n.progressTemplate || '%1$s / %2$s (%3$s%%)';
var percent = total > 0 ? Math.min( 100, Math.round( ( deleted / total ) * 100 ) ) : 100;

return template
.replace( '%1$s', deleted )
.replace( '%2$s', total )
.replace( '%3$s', percent );
};

var updateProgress = function() {
ensureProgressVisible();

var total = state.total > 0 ? state.total : 0;
var deleted = state.deleted > 0 ? state.deleted : 0;
var percent = total > 0 ? Math.min( 100, Math.round( ( deleted / total ) * 100 ) ) : 0;

setProgressPercent( percent );
setProgressText( formatProgress( deleted, total > 0 ? total : deleted ) );
};

var handleError = function( message ) {
setButtonBusy( false );
state.running = false;
showStatus( message || i18n.genericError || '', 'error' );
};

var finishSuccess = function( message ) {
setButtonBusy( false );
state.running = false;
ensureProgressVisible();
setProgressPercent( 100 );
setProgressText( message || i18n.menuDeletedMessage || i18n.completeText || '' );
showStatus( message || i18n.menuDeletedMessage || i18n.completeText || '', 'success' );
};

var ajaxRequest = function( step, extraData ) {
var payload = $.extend( {}, extraData || {}, {
action: settings.action,
nonce: settings.nonce,
step: step
} );

return $.ajax( {
url: ajaxUrl,
type: 'POST',
dataType: 'json',
data: payload
} );
};

var runBatch = function() {
ajaxRequest( 'batch', {
process_id: state.processId,
batch_size: batchSize
} ).done( function( response ) {
if ( ! response || ! response.success ) {
var message = ( response && response.data && response.data.message ) ? response.data.message : i18n.genericError;
handleError( message );
return;
}

var data = response.data || {};

if ( typeof data.total_items !== 'undefined' ) {
state.total = parseInt( data.total_items, 10 ) || state.total;
}

if ( typeof data.deleted_items !== 'undefined' ) {
state.deleted = parseInt( data.deleted_items, 10 ) || state.deleted;
}

updateProgress();

if ( data.complete ) {
finishSuccess( data.message );
return;
}

if ( data.message ) {
showStatus( data.message, 'info' );
}

runBatch();
} ).fail( function( jqXHR ) {
var message = i18n.genericError || '';

if ( jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) {
message = jqXHR.responseJSON.data.message;
}

handleError( message );
} );
};

var startProcess = function() {
if ( state.running ) {
return;
}

state.running = true;
state.processId = '';
state.total = 0;
state.deleted = 0;

setButtonBusy( true );
resetProgress();
ensureProgressVisible();
setProgressText( i18n.preparingText || '' );
setProgressPercent( 0 );
showStatus( '', '' );

ajaxRequest( 'init' ).done( function( response ) {
if ( ! response || ! response.success ) {
var message = ( response && response.data && response.data.message ) ? response.data.message : i18n.genericError;
handleError( message );
return;
}

var data = response.data || {};

state.processId = data.process_id || '';
state.total = data.total_items ? parseInt( data.total_items, 10 ) : 0;
state.deleted = data.deleted_items ? parseInt( data.deleted_items, 10 ) : 0;

if ( data.message ) {
showStatus( data.message, 'info' );
}

if ( data.complete ) {
finishSuccess( data.message );
return;
}

if ( ! state.processId ) {
handleError( i18n.genericError || '' );
return;
}

updateProgress();
runBatch();
} ).fail( function( jqXHR ) {
var message = i18n.genericError || '';

if ( jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) {
message = jqXHR.responseJSON.data.message;
}

handleError( message );
} );
};

$form.on( 'submit', function( event ) {
event.preventDefault();

if ( ! settings.action || ! settings.nonce ) {
return;
}

startProcess();
} );
} );

        $( function() {
                if ( 'undefined' === typeof window.softoneItemImport ) {
                        return;
                }

                var settings = window.softoneItemImport || {};
                var $container = $( settings.containerSelector || '[data-softone-item-import]' );

                if ( ! $container.length ) {
                        return;
                }

                var ajaxUrl = settings.ajaxUrl || window.ajaxurl || '';

                if ( ! ajaxUrl || ! settings.action || ! settings.nonce ) {
                        return;
                }

                var $triggers = $container.find( settings.triggerSelector || '[data-softone-import-trigger]' );

                if ( ! $triggers.length ) {
                        return;
                }

                var $progress = $( settings.progressSelector || '#softone-item-import-progress' );
                var $progressBar = $progress.find( '.softone-progress__bar, .softone-delete-menu-progress__bar' );
                var $progressFill = $progress.find( '.softone-progress__bar-fill, .softone-delete-menu-progress__bar-fill' );
                var $progressText = $( settings.progressTextSelector || '#softone-item-import-progress-text' );
                var $status = $( settings.statusSelector || '#softone-item-import-status' );
                var $lastRun = $( settings.lastRunSelector || '#softone-item-import-last-run' );

                var state = {
                        running: false,
                        processId: '',
                        total: null,
                        processed: 0,
                        created: 0,
                        updated: 0,
                        skipped: 0
                };

                var batchSize = parseInt( settings.batchSize, 10 );

                if ( ! batchSize || batchSize < 1 ) {
                        batchSize = 25;
                }

                var i18n = settings.i18n || {};

                var setButtonsBusy = function( busy ) {
                        $triggers.prop( 'disabled', !! busy );

                        if ( busy ) {
                                $triggers.attr( 'aria-busy', 'true' );
                        } else {
                                $triggers.removeAttr( 'aria-busy' );
                        }
                };

                var ensureProgressVisible = function() {
                        if ( $progress.length ) {
                                $progress.prop( 'hidden', false );
                        }
                };

                var resetProgress = function() {
                        if ( $progressFill.length ) {
                                $progressFill.css( 'width', '0%' );
                        }

                        if ( $progressBar.length ) {
                                $progressBar.attr( 'aria-valuenow', 0 );
                        }

                        if ( $progressText.length ) {
                                $progressText.text( '' );
                        }

                        if ( $progress.length ) {
                                $progress.prop( 'hidden', true );
                        }
                };

                var setProgressPercent = function( percent ) {
                        if ( $progressFill.length ) {
                                $progressFill.css( 'width', percent + '%' );
                        }

                        if ( $progressBar.length ) {
                                $progressBar.attr( 'aria-valuenow', percent );
                        }
                };

                var setProgressText = function( text ) {
                        if ( $progressText.length ) {
                                $progressText.text( text || '' );
                        }
                };

                var showStatus = function( message, type ) {
                        if ( ! $status.length ) {
                                return;
                        }

                        $status.removeClass( 'notice-success notice-error notice-warning notice-info' );

                        if ( ! message ) {
                                $status.prop( 'hidden', true );
                                return;
                        }

                        var className = 'notice';

                        if ( 'success' === type || 'error' === type || 'warning' === type ) {
                                className += ' notice-' + type;
                        } else {
                                className += ' notice-info';
                        }

                        $status.addClass( className ).text( message ).prop( 'hidden', false );
                };

                var formatProgress = function( processed, total ) {
                        if ( total && total > 0 ) {
                                var percent = Math.min( 100, Math.round( ( processed / total ) * 100 ) );
                                var template = i18n.progressTemplate || '%1$s / %2$s (%3$s%%)';
                                return template.replace( '%1$s', processed ).replace( '%2$s', total ).replace( '%3$s', percent );
                        }

                        var indeterminate = i18n.indeterminateTemplate || '%1$s items processed';
                        return indeterminate.replace( '%1$s', processed );
                };

                var updateProgress = function() {
                        ensureProgressVisible();

                        var processed = state.processed > 0 ? state.processed : 0;
                        var total = ( state.total !== null && state.total >= 0 ) ? state.total : null;

                        if ( total && total > 0 ) {
                                var percent = Math.min( 100, Math.round( ( processed / total ) * 100 ) );
                                setProgressPercent( percent );
                                setProgressText( formatProgress( processed, total ) );
                        } else {
                                setProgressPercent( processed > 0 ? 15 : 0 );
                                setProgressText( formatProgress( processed, 0 ) );
                        }
                };

                var handleError = function( message ) {
                        setButtonsBusy( false );
                        state.running = false;
                        showStatus( message || i18n.genericError || '', 'error' );
                };

                var finishSuccess = function( message ) {
                        state.running = false;
                        setButtonsBusy( false );
                        ensureProgressVisible();
                        setProgressPercent( 100 );
                        setProgressText( message || i18n.completeText || '' );
                        showStatus( message || i18n.completeText || '', 'success' );
                };

                var applyLastRunMessage = function( text ) {
                        if ( ! $lastRun.length ) {
                                return;
                        }

                        if ( text ) {
                                $lastRun.text( text ).prop( 'hidden', false );
                        } else {
                                $lastRun.text( '' ).prop( 'hidden', true );
                        }
                };

                var appendWarnings = function( warnings ) {
                        if ( ! warnings || ! warnings.length ) {
                                return '';
                        }

                        var prefix = i18n.warningPrefix || '';
                        return ( prefix ? prefix + ' ' : '' ) + warnings.join( ' ' );
                };

                var ajaxRequest = function( step, data ) {
                        var payload = $.extend( {}, data || {}, {
                                action: settings.action,
                                nonce: settings.nonce,
                                step: step
                        } );

                        return $.ajax( {
                                url: ajaxUrl,
                                type: 'POST',
                                dataType: 'json',
                                data: payload
                        } );
                };

                var runBatch = function() {
                        ajaxRequest( 'batch', {
                                process_id: state.processId,
                                batch_size: batchSize
                        } ).done( function( response ) {
                                if ( ! response || ! response.success ) {
                                        var message = ( response && response.data && response.data.message ) ? response.data.message : i18n.genericError;
                                        handleError( message );
                                        return;
                                }

                                var data = response.data || {};

                                if ( typeof data.total_items !== 'undefined' ) {
                                        if ( null === data.total_items ) {
                                                state.total = null;
                                        } else {
                                                state.total = parseInt( data.total_items, 10 );
                                                if ( isNaN( state.total ) ) {
                                                        state.total = null;
                                                }
                                        }
                                }

                                state.processed = data.processed_items ? parseInt( data.processed_items, 10 ) : 0;
                                state.created   = data.created_items ? parseInt( data.created_items, 10 ) : 0;
                                state.updated   = data.updated_items ? parseInt( data.updated_items, 10 ) : 0;
                                state.skipped   = data.skipped_items ? parseInt( data.skipped_items, 10 ) : 0;

                                updateProgress();

                                var noticeType = data.notice_type || 'info';
                                var statusMessage = data.message || '';

                                if ( data.warnings && data.warnings.length ) {
                                        var warningsText = appendWarnings( data.warnings );
                                        statusMessage = statusMessage ? statusMessage + ' ' + warningsText : warningsText;
                                        if ( 'info' === noticeType ) {
                                                noticeType = 'warning';
                                        }
                                }

                                if ( statusMessage ) {
                                        showStatus( statusMessage, noticeType );
                                }

                                if ( data.last_run_formatted ) {
                                        applyLastRunMessage( data.last_run_formatted );
                                }

                                if ( data.complete ) {
                                        finishSuccess( data.summary_message || data.message );
                                        return;
                                }

                                if ( ! state.running ) {
                                        return;
                                }

                                runBatch();
                        } ).fail( function( jqXHR ) {
                                var message = i18n.genericError || '';

                                if ( jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) {
                                        message = jqXHR.responseJSON.data.message;
                                }

                                handleError( message );
                        } );
                };

                var startProcess = function( options ) {
                        if ( state.running ) {
                                return;
                        }

                        state.running = true;
                        state.processId = '';
                        state.total = null;
                        state.processed = 0;
                        state.created = 0;
                        state.updated = 0;
                        state.skipped = 0;

                        setButtonsBusy( true );
                        resetProgress();
                        ensureProgressVisible();
                        setProgressPercent( 0 );
                        setProgressText( i18n.preparingText || '' );
                        showStatus( '', '' );

                        var payload = $.extend( {}, options || {} );

                        ajaxRequest( 'init', payload ).done( function( response ) {
                                if ( ! response || ! response.success ) {
                                        var message = ( response && response.data && response.data.message ) ? response.data.message : i18n.genericError;
                                        handleError( message );
                                        return;
                                }

                                var data = response.data || {};

                                state.processId = data.process_id || '';
                                if ( typeof data.total_items !== 'undefined' ) {
                                        if ( null === data.total_items ) {
                                                state.total = null;
                                        } else {
                                                state.total = parseInt( data.total_items, 10 );
                                                if ( isNaN( state.total ) ) {
                                                        state.total = null;
                                                }
                                        }
                                }
                                state.processed = data.processed_items ? parseInt( data.processed_items, 10 ) : 0;
                                state.created   = data.created_items ? parseInt( data.created_items, 10 ) : 0;
                                state.updated   = data.updated_items ? parseInt( data.updated_items, 10 ) : 0;
                                state.skipped   = data.skipped_items ? parseInt( data.skipped_items, 10 ) : 0;

                                if ( data.last_run_formatted ) {
                                        applyLastRunMessage( data.last_run_formatted );
                                }

                                if ( data.message ) {
                                        showStatus( data.message, 'info' );
                                }

                                if ( data.complete ) {
                                        finishSuccess( data.summary_message || data.message );
                                        return;
                                }

                                if ( ! state.processId ) {
                                        handleError( i18n.genericError || '' );
                                        return;
                                }

                                updateProgress();
                                runBatch();
                        } ).fail( function( jqXHR ) {
                                var message = i18n.genericError || '';

                                if ( jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) {
                                        message = jqXHR.responseJSON.data.message;
                                }

                                handleError( message );
                        } );
                };

                $triggers.on( 'click', function( event ) {
                        event.preventDefault();

                        var $button = $( this );
                        var options = {
                                force_full_import: $button.data( 'softoneImportForceFull' ) ? 1 : 0,
                                force_taxonomy_refresh: $button.data( 'softoneImportRefreshTaxonomy' ) ? 1 : 0
                        };

                        startProcess( options );
                } );
        } );

})( jQuery );
