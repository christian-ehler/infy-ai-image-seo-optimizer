<?php
/**
 * Language Manager Class
 *
 * Centralized language management with a built-in list of all WordPress-supported
 * languages. Uses translatable English names so the dropdown adapts to the user's
 * admin locale.
 *
 * @package    IPAIS
 * @subpackage Core
 * @since 1.0.0
 */

namespace IPAIS\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class INFYP_Language_Manager
 *
 * Manages language operations for the AI Image SEO plugin.
 * Contains a complete static list of all WordPress.org languages.
 */
class INFYP_Language_Manager {

    /**
     * In-memory cache for languages
     *
     * @var array|null
     */
    private static $languages_cache = null;

    /**
     * Get all available languages
     *
     * Returns an associative array of locale codes and their translatable
     * display names. All 132 WordPress.org languages are always available.
     *
     * @since 1.0.0
     *
     * @return array Array of languages ['en_US' => 'English (United States)', ...]
     */
    public static function get_available_languages() {
        if (self::$languages_cache !== null) {
            return self::$languages_cache;
        }

        self::$languages_cache = self::get_all_languages();

        return self::$languages_cache;
    }

    /**
     * Get language display name by locale code
     *
     * @since 1.0.0
     *
     * @param string $locale Language locale code (e.g., 'en_US', 'de_DE')
     * @return string Display name or the locale if not found
     */
    public static function get_language_name($locale) {
        $languages = self::get_available_languages();
        return isset($languages[$locale]) ? $languages[$locale] : $locale;
    }

    /**
     * Validate if a language code exists
     *
     * @since 1.0.0
     *
     * @param string $locale Language locale code to validate
     * @return bool True if language exists, false otherwise
     */
    public static function is_valid_language($locale) {
        if (empty($locale)) {
            return false;
        }

        $languages = self::get_available_languages();
        return isset($languages[$locale]);
    }

    /**
     * Get default language
     *
     * Returns the site's current locale or English as fallback
     *
     * @since 1.0.0
     *
     * @return string Default language locale code
     */
    public static function get_default_language() {
        $site_locale = get_locale();

        if (self::is_valid_language($site_locale)) {
            return $site_locale;
        }

        return 'en_US';
    }

    /**
     * Clear language cache
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function clear_cache() {
        self::$languages_cache = null;
    }

    /**
     * Get all WordPress.org languages
     *
     * Returns the complete list of all languages supported by WordPress.org,
     * sorted alphabetically by display name. Each name is wrapped in __()
     * so it can be translated to the user's admin locale.
     *
     * Source: https://api.wordpress.org/translations/core/1.0/
     *
     * @since 1.0.0
     * @access private
     *
     * @return array Sorted associative array of locale => display name
     */
    private static function get_all_languages() {
        $languages = array(
            'af'              => __('Afrikaans', 'infy-ai-image-seo-optimizer'),
            'sq'              => __('Albanian', 'infy-ai-image-seo-optimizer'),
            'am'              => __('Amharic', 'infy-ai-image-seo-optimizer'),
            'ar'              => __('Arabic', 'infy-ai-image-seo-optimizer'),
            'arg'             => __('Aragonese', 'infy-ai-image-seo-optimizer'),
            'hy'              => __('Armenian', 'infy-ai-image-seo-optimizer'),
            'as'              => __('Assamese', 'infy-ai-image-seo-optimizer'),
            'az'              => __('Azerbaijani', 'infy-ai-image-seo-optimizer'),
            'eu'              => __('Basque', 'infy-ai-image-seo-optimizer'),
            'bel'             => __('Belarusian', 'infy-ai-image-seo-optimizer'),
            'bn_BD'           => __('Bengali (Bangladesh)', 'infy-ai-image-seo-optimizer'),
            'bs_BA'           => __('Bosnian', 'infy-ai-image-seo-optimizer'),
            'bg_BG'           => __('Bulgarian', 'infy-ai-image-seo-optimizer'),
            'ca'              => __('Catalan', 'infy-ai-image-seo-optimizer'),
            'ceb'             => __('Cebuano', 'infy-ai-image-seo-optimizer'),
            'zh_CN'           => __('Chinese (China)', 'infy-ai-image-seo-optimizer'),
            'zh_HK'           => __('Chinese (Hong Kong)', 'infy-ai-image-seo-optimizer'),
            'zh_TW'           => __('Chinese (Taiwan)', 'infy-ai-image-seo-optimizer'),
            'hr'              => __('Croatian', 'infy-ai-image-seo-optimizer'),
            'cs_CZ'           => __('Czech', 'infy-ai-image-seo-optimizer'),
            'da_DK'           => __('Danish', 'infy-ai-image-seo-optimizer'),
            'nl_NL'           => __('Dutch', 'infy-ai-image-seo-optimizer'),
            'nl_BE'           => __('Dutch (Belgium)', 'infy-ai-image-seo-optimizer'),
            'nl_NL_formal'    => __('Dutch (Formal)', 'infy-ai-image-seo-optimizer'),
            'dzo'             => __('Dzongkha', 'infy-ai-image-seo-optimizer'),
            'en_US'           => __('English (United States)', 'infy-ai-image-seo-optimizer'),
            'en_AU'           => __('English (Australia)', 'infy-ai-image-seo-optimizer'),
            'en_CA'           => __('English (Canada)', 'infy-ai-image-seo-optimizer'),
            'en_NZ'           => __('English (New Zealand)', 'infy-ai-image-seo-optimizer'),
            'en_ZA'           => __('English (South Africa)', 'infy-ai-image-seo-optimizer'),
            'en_GB'           => __('English (UK)', 'infy-ai-image-seo-optimizer'),
            'eo'              => __('Esperanto', 'infy-ai-image-seo-optimizer'),
            'et'              => __('Estonian', 'infy-ai-image-seo-optimizer'),
            'fi'              => __('Finnish', 'infy-ai-image-seo-optimizer'),
            'fr_BE'           => __('French (Belgium)', 'infy-ai-image-seo-optimizer'),
            'fr_CA'           => __('French (Canada)', 'infy-ai-image-seo-optimizer'),
            'fr_FR'           => __('French (France)', 'infy-ai-image-seo-optimizer'),
            'fy'              => __('Frisian', 'infy-ai-image-seo-optimizer'),
            'fur'             => __('Friulian', 'infy-ai-image-seo-optimizer'),
            'gl_ES'           => __('Galician', 'infy-ai-image-seo-optimizer'),
            'ka_GE'           => __('Georgian', 'infy-ai-image-seo-optimizer'),
            'de_DE'           => __('German', 'infy-ai-image-seo-optimizer'),
            'de_AT'           => __('German (Austria)', 'infy-ai-image-seo-optimizer'),
            'de_DE_formal'    => __('German (Formal)', 'infy-ai-image-seo-optimizer'),
            'de_CH'           => __('German (Switzerland)', 'infy-ai-image-seo-optimizer'),
            'de_CH_informal'  => __('German (Switzerland, Informal)', 'infy-ai-image-seo-optimizer'),
            'el'              => __('Greek', 'infy-ai-image-seo-optimizer'),
            'gu'              => __('Gujarati', 'infy-ai-image-seo-optimizer'),
            'haz'             => __('Hazaragi', 'infy-ai-image-seo-optimizer'),
            'he_IL'           => __('Hebrew', 'infy-ai-image-seo-optimizer'),
            'hi_IN'           => __('Hindi', 'infy-ai-image-seo-optimizer'),
            'hu_HU'           => __('Hungarian', 'infy-ai-image-seo-optimizer'),
            'is_IS'           => __('Icelandic', 'infy-ai-image-seo-optimizer'),
            'id_ID'           => __('Indonesian', 'infy-ai-image-seo-optimizer'),
            'ga'              => __('Irish', 'infy-ai-image-seo-optimizer'),
            'it_IT'           => __('Italian', 'infy-ai-image-seo-optimizer'),
            'ja'              => __('Japanese', 'infy-ai-image-seo-optimizer'),
            'jv_ID'           => __('Javanese', 'infy-ai-image-seo-optimizer'),
            'kab'             => __('Kabyle', 'infy-ai-image-seo-optimizer'),
            'kn'              => __('Kannada', 'infy-ai-image-seo-optimizer'),
            'kk'              => __('Kazakh', 'infy-ai-image-seo-optimizer'),
            'km'              => __('Khmer', 'infy-ai-image-seo-optimizer'),
            'ko_KR'           => __('Korean', 'infy-ai-image-seo-optimizer'),
            'ckb'             => __('Kurdish (Sorani)', 'infy-ai-image-seo-optimizer'),
            'kir'             => __('Kyrgyz', 'infy-ai-image-seo-optimizer'),
            'lo'              => __('Lao', 'infy-ai-image-seo-optimizer'),
            'lv'              => __('Latvian', 'infy-ai-image-seo-optimizer'),
            'lt_LT'           => __('Lithuanian', 'infy-ai-image-seo-optimizer'),
            'dsb'             => __('Lower Sorbian', 'infy-ai-image-seo-optimizer'),
            'mk_MK'           => __('Macedonian', 'infy-ai-image-seo-optimizer'),
            'ms_MY'           => __('Malay', 'infy-ai-image-seo-optimizer'),
            'ml_IN'           => __('Malayalam', 'infy-ai-image-seo-optimizer'),
            'mr'              => __('Marathi', 'infy-ai-image-seo-optimizer'),
            'mn'              => __('Mongolian', 'infy-ai-image-seo-optimizer'),
            'ary'             => __('Moroccan Arabic', 'infy-ai-image-seo-optimizer'),
            'my_MM'           => __('Myanmar (Burmese)', 'infy-ai-image-seo-optimizer'),
            'ne_NP'           => __('Nepali', 'infy-ai-image-seo-optimizer'),
            'nb_NO'           => __('Norwegian (Bokmål)', 'infy-ai-image-seo-optimizer'),
            'nn_NO'           => __('Norwegian (Nynorsk)', 'infy-ai-image-seo-optimizer'),
            'oci'             => __('Occitan', 'infy-ai-image-seo-optimizer'),
            'pa_IN'           => __('Panjabi (India)', 'infy-ai-image-seo-optimizer'),
            'ps'              => __('Pashto', 'infy-ai-image-seo-optimizer'),
            'fa_IR'           => __('Persian', 'infy-ai-image-seo-optimizer'),
            'fa_AF'           => __('Persian (Afghanistan)', 'infy-ai-image-seo-optimizer'),
            'pl_PL'           => __('Polish', 'infy-ai-image-seo-optimizer'),
            'pt_AO'           => __('Portuguese (Angola)', 'infy-ai-image-seo-optimizer'),
            'pt_BR'           => __('Portuguese (Brazil)', 'infy-ai-image-seo-optimizer'),
            'pt_PT'           => __('Portuguese (Portugal)', 'infy-ai-image-seo-optimizer'),
            'pt_PT_ao90'      => __('Portuguese (Portugal, AO90)', 'infy-ai-image-seo-optimizer'),
            'rhg'             => __('Rohingya', 'infy-ai-image-seo-optimizer'),
            'ro_RO'           => __('Romanian', 'infy-ai-image-seo-optimizer'),
            'ru_RU'           => __('Russian', 'infy-ai-image-seo-optimizer'),
            'sah'             => __('Sakha', 'infy-ai-image-seo-optimizer'),
            'skr'             => __('Saraiki', 'infy-ai-image-seo-optimizer'),
            'gd'              => __('Scottish Gaelic', 'infy-ai-image-seo-optimizer'),
            'sr_RS'           => __('Serbian', 'infy-ai-image-seo-optimizer'),
            'szl'             => __('Silesian', 'infy-ai-image-seo-optimizer'),
            'snd'             => __('Sindhi', 'infy-ai-image-seo-optimizer'),
            'si_LK'           => __('Sinhala', 'infy-ai-image-seo-optimizer'),
            'sk_SK'           => __('Slovak', 'infy-ai-image-seo-optimizer'),
            'sl_SI'           => __('Slovenian', 'infy-ai-image-seo-optimizer'),
            'azb'             => __('South Azerbaijani', 'infy-ai-image-seo-optimizer'),
            'es_AR'           => __('Spanish (Argentina)', 'infy-ai-image-seo-optimizer'),
            'es_CL'           => __('Spanish (Chile)', 'infy-ai-image-seo-optimizer'),
            'es_CO'           => __('Spanish (Colombia)', 'infy-ai-image-seo-optimizer'),
            'es_CR'           => __('Spanish (Costa Rica)', 'infy-ai-image-seo-optimizer'),
            'es_DO'           => __('Spanish (Dominican Republic)', 'infy-ai-image-seo-optimizer'),
            'es_EC'           => __('Spanish (Ecuador)', 'infy-ai-image-seo-optimizer'),
            'es_GT'           => __('Spanish (Guatemala)', 'infy-ai-image-seo-optimizer'),
            'es_MX'           => __('Spanish (Mexico)', 'infy-ai-image-seo-optimizer'),
            'es_PE'           => __('Spanish (Peru)', 'infy-ai-image-seo-optimizer'),
            'es_PR'           => __('Spanish (Puerto Rico)', 'infy-ai-image-seo-optimizer'),
            'es_ES'           => __('Spanish (Spain)', 'infy-ai-image-seo-optimizer'),
            'es_UY'           => __('Spanish (Uruguay)', 'infy-ai-image-seo-optimizer'),
            'es_VE'           => __('Spanish (Venezuela)', 'infy-ai-image-seo-optimizer'),
            'sw'              => __('Swahili', 'infy-ai-image-seo-optimizer'),
            'sv_SE'           => __('Swedish', 'infy-ai-image-seo-optimizer'),
            'tl'              => __('Tagalog', 'infy-ai-image-seo-optimizer'),
            'tah'             => __('Tahitian', 'infy-ai-image-seo-optimizer'),
            'ta_IN'           => __('Tamil', 'infy-ai-image-seo-optimizer'),
            'ta_LK'           => __('Tamil (Sri Lanka)', 'infy-ai-image-seo-optimizer'),
            'tt_RU'           => __('Tatar', 'infy-ai-image-seo-optimizer'),
            'te'              => __('Telugu', 'infy-ai-image-seo-optimizer'),
            'th'              => __('Thai', 'infy-ai-image-seo-optimizer'),
            'bo'              => __('Tibetan', 'infy-ai-image-seo-optimizer'),
            'tr_TR'           => __('Turkish', 'infy-ai-image-seo-optimizer'),
            'ug_CN'           => __('Uighur', 'infy-ai-image-seo-optimizer'),
            'uk'              => __('Ukrainian', 'infy-ai-image-seo-optimizer'),
            'hsb'             => __('Upper Sorbian', 'infy-ai-image-seo-optimizer'),
            'ur'              => __('Urdu', 'infy-ai-image-seo-optimizer'),
            'uz_UZ'           => __('Uzbek', 'infy-ai-image-seo-optimizer'),
            'vi'              => __('Vietnamese', 'infy-ai-image-seo-optimizer'),
            'cy'              => __('Welsh', 'infy-ai-image-seo-optimizer'),
        );

        asort($languages);

        return $languages;
    }

}
