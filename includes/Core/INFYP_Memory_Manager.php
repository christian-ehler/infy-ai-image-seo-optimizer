<?php
namespace IPAIS\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Memory Management for AI Image SEO
 *
 * Provides memory monitoring and optimization capabilities to prevent
 * out-of-memory errors during image processing operations.
 *
 * @package IPAIS\Core
 * @since 1.0.0
 */
class INFYP_Memory_Manager {
    
    /**
     * Check if sufficient memory is available for processing.
     * 
     * @since 1.0.0
     * @param int $required_mb Required memory in megabytes.
     * @return bool True if sufficient memory is available, false otherwise.
     */
    public static function has_sufficient_memory( $required_mb = 50 ) {
        // Unlimited memory — always sufficient.
        if ( (int) ini_get( 'memory_limit' ) === -1 ) {
            return true;
        }

        $memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
        $memory_usage = memory_get_usage( true );
        $available_memory = $memory_limit - $memory_usage;
        $required_bytes = $required_mb * 1024 * 1024;

        return $available_memory > $required_bytes;
    }
    
    /**
     * Get current memory usage as a percentage of the limit.
     * 
     * @since 1.0.0
     * @return float Memory usage percentage (0-100).
     */
    public static function get_memory_usage_percentage() {
        // Unlimited memory — report 0% usage.
        if ( (int) ini_get( 'memory_limit' ) === -1 ) {
            return 0.0;
        }

        $memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
        $memory_usage = memory_get_usage( true );

        return ( $memory_usage / $memory_limit ) * 100;
    }
    
    /**
     * Free memory after operations
     */
    public static function free_memory() {
        // Clear plugin-specific caches only (avoid flushing entire object cache).
        $infyp_cache = \IPAIS\Core\INFYP_Cache_Manager::get_instance();
        $infyp_cache->clear_all_cache();
        
        // Force garbage collection if available
        if ( function_exists( 'gc_collect_cycles' ) ) {
            gc_collect_cycles();
        }
    }
    
    /**
     * Monitor memory during bulk operations
     * 
     * @param string $operation_name
     * @return bool Whether to continue operation
     */
    public static function monitor_memory_usage( $operation_name = 'bulk_operation' ) {
        $usage_percentage = self::get_memory_usage_percentage();
        
        if ( $usage_percentage > 80 ) {
            INFYP_Logger::log(
                sprintf( 'High memory usage detected during %s: %.2f%%', $operation_name, $usage_percentage ),
                'WARNING'
            );
            
            // Try to free memory
            self::free_memory();
            
            // Check again
            $usage_percentage = self::get_memory_usage_percentage();
            
            if ( $usage_percentage > 90 ) {
                INFYP_Logger::log(
                    sprintf( 'Critical memory usage during %s: %.2f%%. Stopping operation.', $operation_name, $usage_percentage ),
                    'ERROR'
                );
                return false;
            }
        }
        
        return true;
    }
}