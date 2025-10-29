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
})( jQuery );
