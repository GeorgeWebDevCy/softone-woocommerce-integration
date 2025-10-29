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
        } );
})( jQuery );
