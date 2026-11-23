/**
 * Configuration Module - SEO Scoring Parameters and Weights
 *
 * This module manages all configuration-related functions for SEO scoring,
 * including parameter caching and weight distribution for optimal performance.
 * Provides centralized access to scoring parameters used across the plugin.
 *
 * ## Features
 *
 * - SEO scoring parameter retrieval (alt, filename, title limits)
 * - Weight distribution configuration for score calculation
 * - Caching for performance optimization
 * - Fallback defaults when server data unavailable
 *
 * ## Dependencies
 *
 * - infyp_vars global (optional, provides server-side configuration)
 *
 * @file Configuration Module
 * @author InfyPress
 * @since 1.0.0
 * @module core/config
 */

/**
 * Export as ES6 module with WordPress compatibility fallback
 * Note: This module does not require jQuery
 */
const config = (function() {
    'use strict';

    // Cache for performance optimization
    let cachedParams = null;  // SEO scoring parameters
    let cachedWeights = null; // SEO score weight distribution

    /**
     * Get SEO scoring parameters for field validation
     *
     * Defines min/max character and word counts for optimal SEO.
     * Uses server-provided values from infyp_vars when available,
     * falling back to sensible defaults.
     *
     * @since 1.0.0
     * @access public
     * @returns {Object} Scoring parameters for alt, filename, and title
     * @returns {Object} return.alt - Alt text parameters (min/max chars/words)
     * @returns {Object} return.filename - Filename parameters (min/max chars/words)
     * @returns {Object} return.title - Title parameters (min/max chars/words)
     */
    function getScoringParams() {
        if (!cachedParams) {
            if (typeof infyp_vars !== 'undefined' && infyp_vars.scoring_parameters) {
                cachedParams = {
                    alt: infyp_vars.scoring_parameters.alt_text || infyp_vars.scoring_parameters.alt || {
                        min_chars: 50,
                        max_chars: 125,
                        min_words: 10,
                        max_words: 16
                    },
                    filename: infyp_vars.scoring_parameters.filename || {
                        min_chars: 40,
                        max_chars: 60,
                        min_words: 3,
                        max_words: 6
                    },
                    title: infyp_vars.scoring_parameters.title || {
                        min_chars: 40,
                        max_chars: 60,
                        min_words: 6,
                        max_words: 10
                    }
                };
            } else {
                // Fallback defaults if infyp_vars is not available
                cachedParams = {
                    alt: {
                        min_chars: 50,
                        max_chars: 125,
                        min_words: 10,
                        max_words: 16
                    },
                    filename: {
                        min_chars: 40,
                        max_chars: 60,
                        min_words: 3,
                        max_words: 6
                    },
                    title: {
                        min_chars: 40,
                        max_chars: 60,
                        min_words: 6,
                        max_words: 10
                    }
                };
            }
        }
        return cachedParams;
    }

    /**
     * Get alt text parameters
     *
     * Helper function to consistently retrieve alt text parameters.
     *
     * @since 1.0.0
     * @access public
     * @returns {Object} Alt text parameters with min/max values
     * @returns {number} return.min_chars - Minimum character count
     * @returns {number} return.max_chars - Maximum character count
     * @returns {number} return.min_words - Minimum word count
     * @returns {number} return.max_words - Maximum word count
     */
    function getAltParams() {
        const params = getScoringParams();
        return params.alt;
    }

    /**
     * Get filename parameters
     *
     * Helper function to consistently retrieve filename parameters.
     *
     * @since 1.0.0
     * @access public
     * @returns {Object} Filename parameters with min/max values
     * @returns {number} return.min_chars - Minimum character count
     * @returns {number} return.max_chars - Maximum character count
     * @returns {number} return.min_words - Minimum word count
     * @returns {number} return.max_words - Maximum word count
     */
    function getFilenameParams() {
        const params = getScoringParams();
        return params.filename;
    }

    /**
     * Get title parameters
     *
     * Helper function to consistently retrieve title parameters.
     *
     * @since 1.0.0
     * @access public
     * @returns {Object} Title parameters with min/max values
     * @returns {number} return.min_chars - Minimum character count
     * @returns {number} return.max_chars - Maximum character count
     * @returns {number} return.min_words - Minimum word count
     * @returns {number} return.max_words - Maximum word count
     */
    function getTitleParams() {
        const params = getScoringParams();
        return params.title;
    }

    /**
     * Get SEO score weight distribution
     *
     * Defines how much each factor contributes to the total SEO score.
     * Total of all weights should equal 100.
     *
     * @since 1.0.0
     * @access public
     * @returns {Object} Weight values for each scoring factor (total = 100)
     * @returns {number} return.alt_presence - Weight for alt text existence (20)
     * @returns {number} return.alt_quality - Weight for alt text quality (15)
     * @returns {number} return.title_presence - Weight for title existence (15)
     * @returns {number} return.title_quality - Weight for title quality (10)
     * @returns {number} return.filename_quality - Weight for filename SEO-friendliness (15)
     * @returns {number} return.keyword_density - Weight for keyword usage (15)
     * @returns {number} return.uniqueness - Weight for content uniqueness (10)
     */
    function getWeights() {
        if (!cachedWeights) {
            cachedWeights = (typeof infyp_vars !== 'undefined' && infyp_vars.seo_weights) || {
                alt_presence: 20,      // Alt text exists
                alt_quality: 15,       // Alt text meets quality criteria
                title_presence: 15,    // Title exists
                title_quality: 10,     // Title meets quality criteria
                filename_quality: 15,  // Filename is SEO-friendly
                keyword_density: 15,   // Appropriate keyword usage
                uniqueness: 10         // Content is unique
            };
        }
        return cachedWeights;
    }

    /**
     * Clear cached parameters and weights
     *
     * Useful for testing or when configuration changes. Forces
     * parameters to be re-read from infyp_vars on next access.
     *
     * @since 1.0.0
     * @access public
     * @returns {void}
     */
    function clearCache() {
        cachedParams = null;
        cachedWeights = null;
    }

    // Public API
    return {
        getScoringParams,
        getAltParams,
        getFilenameParams,
        getTitleParams,
        getWeights,
        clearCache
    };

})();

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = config;
}

// Export for WordPress/global scope
if (typeof window !== 'undefined') {
    window.infyp = window.infyp || {};
    window.infyp.config = config;
}

export default config;
