<?php
/**
 * File-based logger for Softone synchronisation activity.
 *
 * @package Softone_Woocommerce_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Softone_Sync_Activity_Logger' ) ) {
    /**
     * Persists sync activity details in a JSON lines file inside the uploads directory.
     */
    class Softone_Sync_Activity_Logger {

        const DEFAULT_FILENAME = 'softone-sync-activity.log';

        /**
         * Cached uploads directory data.
         *
         * @var array<string, mixed>|null
         */
        protected $upload_dir = null;

        /**
         * Log an activity entry.
         *
         * @param string               $channel Activity channel (e.g. product_categories).
         * @param string               $action  Action key describing the event.
         * @param string               $message Human readable summary.
         * @param array<string, mixed> $context Additional structured context.
         *
         * @return void
         */
        public function log( $channel, $action, $message, array $context = array() ) {
            $file_path = $this->get_log_file_path();

            if ( '' === $file_path ) {
                return;
            }

            $this->maybe_prepare_directory( $file_path );

            $entry = array(
                'timestamp' => $this->get_timestamp(),
                'channel'   => (string) $channel,
                'action'    => (string) $action,
                'message'   => (string) $message,
                'context'   => $this->normalise_context( $context ),
            );

            $encoded = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES );

            if ( false === $encoded ) {
                $encoded = json_encode( $entry ); // Fallback for environments without wp_json_encode.
            }

            if ( false === $encoded || '' === $encoded ) {
                return;
            }

            $encoded .= PHP_EOL;

            if ( function_exists( 'file_put_contents' ) ) {
                @file_put_contents( $file_path, $encoded, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }

        /**
         * Retrieve the most recent log entries.
         *
         * @param int $limit Maximum number of entries to return.
         *
         * @return array<int, array<string, mixed>>
         */
        public function get_entries( $limit = 200 ) {
            $file_path = $this->get_log_file_path();

            if ( '' === $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
                return array();
            }

            $lines = @file( $file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

            if ( false === $lines || empty( $lines ) ) {
                return array();
            }

            $lines   = array_reverse( $lines );
            $entries = array();

            foreach ( $lines as $line ) {
                $decoded = json_decode( $line, true );

                if ( ! is_array( $decoded ) ) {
                    continue;
                }

                $entries[] = array(
                    'timestamp' => isset( $decoded['timestamp'] ) ? (int) $decoded['timestamp'] : 0,
                    'channel'   => isset( $decoded['channel'] ) ? (string) $decoded['channel'] : '',
                    'action'    => isset( $decoded['action'] ) ? (string) $decoded['action'] : '',
                    'message'   => isset( $decoded['message'] ) ? (string) $decoded['message'] : '',
                    'context'   => isset( $decoded['context'] ) && is_array( $decoded['context'] ) ? $decoded['context'] : array(),
                );

                if ( count( $entries ) >= $limit ) {
                    break;
                }
            }

            return $entries;
        }

        /**
         * Remove the log file.
         *
         * @return bool True on success, false otherwise.
         */
        public function clear() {
            $file_path = $this->get_log_file_path();

            if ( '' === $file_path || ! file_exists( $file_path ) ) {
                return true;
            }

            return @unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        /**
         * Retrieve metadata describing the underlying log file.
         *
         * @return array<string, mixed>
         */
        public function get_metadata() {
            $file_path = $this->get_log_file_path();

            $metadata = array(
                'file_path' => $file_path,
                'exists'    => false,
                'size'      => 0,
            );

            if ( '' !== $file_path && file_exists( $file_path ) ) {
                $metadata['exists'] = true;
                $metadata['size']   = (int) filesize( $file_path );
            }

            return $metadata;
        }

        /**
         * Prepare and cache the uploads directory information.
         *
         * @return array<string, mixed>
         */
        protected function get_upload_dir() {
            if ( null !== $this->upload_dir ) {
                return $this->upload_dir;
            }

            if ( ! function_exists( 'wp_upload_dir' ) ) {
                $this->upload_dir = array();

                return $this->upload_dir;
            }

            $uploads = wp_upload_dir();

            if ( ! is_array( $uploads ) ) {
                $uploads = array();
            }

            $this->upload_dir = $uploads;

            return $this->upload_dir;
        }

        /**
         * Resolve the absolute path to the log file.
         *
         * @return string
         */
        protected function get_log_file_path() {
            $uploads = $this->get_upload_dir();

            if ( empty( $uploads['basedir'] ) ) {
                return '';
            }

            $base_dir = (string) $uploads['basedir'];
            $separator = defined( 'DIRECTORY_SEPARATOR' ) ? DIRECTORY_SEPARATOR : '/';
            $base_dir  = rtrim( $base_dir, '/\\' ) . $separator . 'softone-sync-logs';

            return rtrim( $base_dir, '/\\' ) . $separator . self::DEFAULT_FILENAME;
        }

        /**
         * Ensure the destination directory exists before attempting to write entries.
         *
         * @param string $file_path Target file path.
         *
         * @return void
         */
        protected function maybe_prepare_directory( $file_path ) {
            $directory = dirname( $file_path );

            if ( ! is_dir( $directory ) && function_exists( 'wp_mkdir_p' ) ) {
                wp_mkdir_p( $directory );
            } elseif ( ! is_dir( $directory ) && function_exists( 'mkdir' ) ) {
                @mkdir( $directory, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }

        /**
         * Retrieve a current timestamp aligned with WordPress configuration when possible.
         *
         * @return int
         */
        protected function get_timestamp() {
            if ( function_exists( 'current_time' ) ) {
                return (int) current_time( 'timestamp' );
            }

            return time();
        }

        /**
         * Ensure the context payload only contains serialisable data.
         *
         * @param array<string, mixed> $context Raw context payload.
         *
         * @return array<string, mixed>
         */
        protected function normalise_context( array $context ) {
            foreach ( $context as $key => $value ) {
                if ( is_object( $value ) ) {
                    $context[ $key ] = $this->convert_object_to_array( $value );
                }
            }

            return $context;
        }

        /**
         * Convert an object into an array recursively for logging.
         *
         * @param mixed $value Input value.
         *
         * @return mixed
         */
        protected function convert_object_to_array( $value ) {
            if ( is_object( $value ) ) {
                $value = (array) $value;
            }

            if ( is_array( $value ) ) {
                foreach ( $value as $key => $data ) {
                    $value[ $key ] = $this->convert_object_to_array( $data );
                }
            }

            return $value;
        }
    }
}
