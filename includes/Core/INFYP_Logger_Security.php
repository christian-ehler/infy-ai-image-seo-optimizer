<?php
namespace IPAIS\Core;

/**
 * Security helper for INFYP_Logger
 *
 * @package AI_Image_SEO_InfyPress
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    die;
}

/**
 * Class INFYP_Logger_Security
 * 
 * Handles security measures for the logger directory
 */
if ( ! class_exists( 'INFYP_Logger_Security' ) ) {
class INFYP_Logger_Security {
    
    /**
     * Creates .htaccess protection for log directory
     * 
     * @param string $log_dir The log directory path
     */
    public static function protect_log_directory( $log_dir ) {
        // Validate path is within the WordPress content directory
        $log_dir = wp_normalize_path( trailingslashit( $log_dir ) );
        $content_dir = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) );

        if ( strpos( $log_dir, $content_dir ) !== 0 ) {
            return;
        }

        if ( ! is_dir( $log_dir ) ) {
            return;
        }

        global $wp_filesystem;

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( empty( $wp_filesystem ) ) {
            WP_Filesystem();
        }
        if ( empty( $wp_filesystem ) ) {
            return;
        }

        $htaccess_file = $log_dir . '.htaccess';
        $htaccess_content  = "# AI Image SEO Log Protection\n";
        $htaccess_content .= "<IfModule mod_authz_core.c>\n";
        $htaccess_content .= "  Require all denied\n";
        $htaccess_content .= "</IfModule>\n";
        $htaccess_content .= "<IfModule !mod_authz_core.c>\n";
        $htaccess_content .= "  Order deny,allow\n";
        $htaccess_content .= "  Deny from all\n";
        $htaccess_content .= "</IfModule>\n";

        if ( ! $wp_filesystem->exists( $htaccess_file ) ) {
            $wp_filesystem->put_contents( $htaccess_file, $htaccess_content, FS_CHMOD_FILE );
        }

        // Also create index.php for additional protection
        $index_file = $log_dir . 'index.php';
        $index_content = "<?php\n// Silence is golden.";

        if ( ! $wp_filesystem->exists( $index_file ) ) {
            $wp_filesystem->put_contents( $index_file, $index_content, FS_CHMOD_FILE );
        }
    }
}
} // End if class_exists check