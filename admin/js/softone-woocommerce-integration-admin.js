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
})( jQuery );
