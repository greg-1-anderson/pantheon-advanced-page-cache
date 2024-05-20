<?php
/**
 * Controller for the admin interface that builds on top of the Pantheon MU plugin.
 *
 * @since 2.0.0
 * @package Pantheon_Advanced_Page_Cache
 */

namespace Pantheon_Advanced_Page_Cache\Admin_Interface;

/**
 * Kick off the important bits.
 *
 * @since 2.0.0
 * @return void
 */
function bootstrap() {
	// Check if wp_admin_notice exists. We've already noted that the plugin requires at least 6.4, so we're going to not display a notice if you didn't listen to the recommendation.
	if ( ! function_exists( 'wp_admin_notice' ) ) {
		add_filter( 'pantheon_apc_disable_admin_notices', '__return_true' );
	}

	if ( defined( 'PANTHEON_MU_PLUGIN_VERSION' ) ) {
		// Only do things here if we've got the MU plugin and it's > 1.4.0.
		if ( version_compare( PANTHEON_MU_PLUGIN_VERSION, '1.4.0', '>' ) ) {
			// Do stuff, e.g. add_action().
			add_action( 'admin_notices', __NAMESPACE__ . '\\admin_notice_maybe_recommend_higher_max_age' );
		} else {
			add_action( 'admin_notices', __NAMESPACE__ . '\\admin_notice_old_mu_plugin' );
		}
	} else {
		add_action( 'admin_notices', __NAMESPACE__ . '\\admin_notice_no_mu_plugin' );
	}

	add_filter( 'site_status_tests', __NAMESPACE__ . '\\default_cache_max_age_test' );
	add_action( 'update_option_pantheon-cache', __NAMESPACE__ . '\\clear_max_age_compare_cache' );
	add_action( 'admin_init', __NAMESPACE__ . '\\set_max_age_to_default' );
	add_action( 'admin_notices', __NAMESPACE__ . '\\max_age_updated_admin_notice' );
}

/**
 * Display an admin notice if the Pantheon MU plugin was not found.
 *
 * @since 2.0.0
 * @return void
 */
function admin_notice_no_mu_plugin() {
	/**
	 * Allow disabling the admin notice.
	 *
	 * @param bool $disable_admin_notices Whether to disable the admin notice.
	 * @param string $callback The name of the current callback function.
	 */
	if ( apply_filters( 'pantheon_apc_disable_admin_notices', false, __FUNCTION__ ) ) {
		return;
	}

	wp_admin_notice(
		// translators: %s is a link to the Pantheon MU plugin.
		sprintf( __( 'Pantheon Advanced Page Cache works best on the Pantheon platform. If you are working inside a Pantheon environment, ensure your site is using the <a href="%s">Pantheon MU plugin</a>.', 'pantheon-advanced-page-cache' ), 'https://github.com/pantheon-systems/pantheon-mu-plugin' ),
		[
			'type' => 'error',
			'dismissible' => true,
		]
	);
}

/**
 * Display an admin notice if the Pantheon MU plugin is out of date.
 *
 * @since 2.0.0
 * @return void
 */
function admin_notice_old_mu_plugin() {
	$current_screen = get_current_screen();

	if ( apply_filters( 'pantheon_apc_disable_admin_notices', false, __FUNCTION__ ) || 'settings_page_pantheon-cache' !== $current_screen->id ) {
		return;
	}

	$mu_plugin_version = PANTHEON_MU_PLUGIN_VERSION;
	$message = sprintf(
		// translators: %1$s is a link to the Pantheon MU plugin, %2$s is the version of the MU plugin.
		__( 'You appear to have an old version of the <a href="%1$s">Pantheon MU plugin</a>. 1.4.0 or above expected but %2$s found.', 'pantheon-advanced-page-cache' ),
		'https://github.com/pantheon-systems/pantheon-mu-plugin',
		$mu_plugin_version
	);

	// Check if there's a composer.json file in the root of the site.
	if ( file_exists( ABSPATH . 'composer.json' ) ) {
		$message .= ' ' . __( 'If you are using Composer, you can update the MU plugin by running <code>composer update</code>.', 'pantheon-advanced-page-cache' );
	} else {
		$message .= ' ' . __( 'You should Apply Updates from the Pantheon Dashboard to get the latest version of WordPress and the Pantheon MU plugin.', 'pantheon-advanced-page-cache' );
	}

	wp_admin_notice(
		// translators: %s is a link to the Pantheon MU plugin.
		$message,
		[
			'type' => 'warning',
			'dismissible' => true,
		]
	);
}

/**
 * Display an admin notice if the max-age is less than a week but not equal to 600 seconds.
 *
 * @since 2.0.0
 * @return void
 */
function admin_notice_maybe_recommend_higher_max_age() {
	$current_screen = get_current_screen();
	$global_warning_shown = current_user_can( 'manage_options' ) ? get_user_meta( get_current_user_id(), 'pantheon_max_age_global_warning_notice', true ) : true;

	if ( apply_filters( 'pantheon_apc_disable_admin_notices', false, __FUNCTION__ ) || $global_warning_shown ) {
		return;
	}

	$message = '';
	$dismissable = true;
	$max_age_rank = max_age_compare();
	$current_max_age = get_current_max_age();

	// Check if the max-age rank is acceptable or if current max-age is 600 seconds and we haven't yet reset it to the default.
	if (
		$max_age_rank === 0 ||
		( $current_max_age === 600 && ! get_option( 'pantheon_max_age_updated', false ) )
	) {
		return;
	}

	if ( isset( $current_screen->id ) && 'settings_page_pantheon-cache' === $current_screen->id ) {
			// If the current max-age value has a rank of 3 or more (10 is the highest), we'll note that it's very low.
		$very_low = $max_age_rank > 3 ? __( 'This is a very low value and may not be optimal for your site.', 'pantheon-advanced-page-cache' ) : '';
		$message = sprintf(
			// translators: %1$s is the current max-age, %2$d is the current max-age in seconds, %3$s is a message that displays if the value is very low, %44d is the recommended max age in seconds, %5$s is the humanized recommended max age, %6$s is debug information that is written to the HTML DOM but not displayed.
			__( 'The cache max-age is currently set to %1$s. %2$s Consider increasing the cache max-age to at least %3$s.%4$s', 'pantheon-advanced-page-cache' ),
			humanized_max_age(),
			$very_low,
			humanized_max_age( true ),
			sprintf( '<!-- Max Age Rank: %d -->', $max_age_rank )
		);
	}

	// Global notice on all pages _except_ the Pantheon cache settings page.
	if ( ! isset( $current_screen->id ) || 'settings_page_pantheon-cache' !== $current_screen->id ) {
		$message = sprintf(
			// translators: %s is a link to the Pantheon GCDN configuration page.
			__( 'Your site\'s cache max-age is set below the recommendation (1 week). Visit the <a href="%1$s">Pantheon GCDN configuration page</a> to update the setting.%2$s' ),
			admin_url( 'options-general.php?page=pantheon-cache' ),
			sprintf( '<!-- Max Age Rank: %d -->', $max_age_rank )
		);
		$dismissable = false;
		update_user_meta( get_current_user_id(), 'pantheon_max_age_global_warning_notice', true );
	}

	if ( ! empty( $message ) ) {
		// Escalating notice types based on the max-age rank.
		$notice_type = ( $max_age_rank === 1 ? 'info' : $max_age_rank > 3 ) ? 'error' : 'warning';

		wp_admin_notice(
			$message,
			[
				'type' => $notice_type,
				'dismissible' => $dismissable,
			]
		);
	}
}

/**
 * Get the current max-age value.
 *
 * This comes from the Pantheon mu-plugin and only exists if settings were actually saved.
 *
 * If the site existed prior to 1.4.0 of the mu-plugin, the default value is 600 seconds. Otherwise, the default value is 1 week.
 *
 * @since 2.0.0
 * @return int
 */
function get_current_max_age() {
	$options = get_option( 'pantheon-cache', [] );

	// If the default_ttl option is not set, we're using the default, which is 1 week.
	if ( ! isset( $options['default_ttl'] ) ) {
		return get_default_max_age();
	}

	return apply_filters( 'pantheon_cache_default_max_age', $options['default_ttl'] );
}

/**
 * Add a test to the Site Health page to check the cache max-age.
 *
 * @param array $tests The Site Health tests.
 *
 * @since 2.0.0
 * @return array
 */
function default_cache_max_age_test( $tests ) {
	$tests['direct']['pantheon_edge_cache'] = [
		'label' => __( 'Pantheon Edge Cache', 'pantheon-advanced-page-cache' ),
		'test' => __NAMESPACE__ . '\\test_cache_max_age',
	];

	return $tests;
}

/**
 * Get the humanized max-age.
 *
 * @param bool $recommended Whether to get the recommended max-age.
 *
 * @since 2.0.0
 * @return string
 */
function humanized_max_age( $recommended = false ) {
	$time = time();
	$current_max_age = $recommended ? get_default_max_age() : get_current_max_age();
	$humanized_time = human_time_diff( $time, $time + $current_max_age );

	return $humanized_time;
}

/**
 * Get the default max-age.
 *
 * @since 2.0.0
 * @return int
 */
function get_default_max_age() {
	return apply_filters( 'pantheon_cache_default_max_age', WEEK_IN_SECONDS );
}

/**
 * Compare the current max-age to the default max-age.
 *
 * @since 2.0.0
 * @return int A ranked value from 0 to 10 where 0 is optimal (equal to or greater than the recommended max age) and 10 is very bad.
 */
function max_age_compare() {
	$cached_rank = get_transient( 'papc_max_age_compare' );

	if ( false !== $cached_rank ) {
		return $cached_rank;
	}

	$current_max_age = get_current_max_age();
	$default_max_age = get_default_max_age();
	$diff = $current_max_age - $default_max_age;

	if ( $diff >= 0 ) {
		return 0;
	}

	// Rank the difference on a scale of 0 ($current_max_age >= $default_max_age) to 10 and return the rank int.
	$rank = round( abs( $diff ) / $default_max_age * 10 );

	$cached_rank = min( max( $rank, 1 ), 10 );
	set_transient( 'papc_max_age_compare', $cached_rank, WEEK_IN_SECONDS );
	return $cached_rank;
}

/**
 * The GCDN cache max-age Site Health test.
 *
 * @since 2.0.0
 * @return array
 */
function test_cache_max_age() {
	$default_max_age = get_default_max_age();
	$current_max_age = get_current_max_age();
	$humanized_time = humanized_max_age();
	$humanized_reccomended_time = humanized_max_age( true );
	$recommend_color = max_age_compare() > 3 ? 'red' : 'orange';

	if ( $current_max_age < $default_max_age ) {
		$result = [
			'label' => __( 'Pantheon GCDN Cache Max-Age', 'pantheon-advanced-page-cache' ),
			'status' => 'recommended',
			'badge' => [
				'label' => __( 'Performance', 'pantheon-advanced-page-cache' ),
				'color' => $recommend_color,
			],
			'description' => sprintf(
				// translators: %1$s is the current max-age, %2$s is the recommended max-age, %3$d is the recommended max-age in seconds.
				__( 'The Pantheon GCDN cache max-age is currently set to %1$s (%2$d seconds). We recommend increasing to %3$s (%4$d seconds).', 'pantheon-advanced-page-cache' ),
				$humanized_time,
				$current_max_age,
				$humanized_reccomended_time,
				$default_max_age
			),
			'test' => 'pantheon_edge_cache',
		];

		return $result;
	}

	$result = [
		'label' => sprintf(
			// translators: %s is the humanized time.
			__( 'Pantheon GCDN Cache Max-Age set to %1$s', 'pantheon-advanced-page-cache' ),
			$humanized_time,
			$humanized_reccomended_time
		),
		'status' => 'good',
		'badge' => [
			'label' => __( 'Performance', 'pantheon-advanced-page-cache' ),
			'color' => 'blue',
		],
		'description' => sprintf(
			'%1$s<br />%2$s',
			sprintf(
				// translators: %1$s is the current max-age, %2$s is the recommended max-age, %3$d is the recommended max-age in seconds.
				__( 'The Pantheon cache max-age is currently set to %1$s (%2$s seconds). Our recommendation is %3$s (%4$d seconds) or more.', 'pantheon-advanced-page-cache' ),
				$humanized_time,
				$current_max_age,
				$humanized_reccomended_time,
				$default_max_age
			),
			sprintf(
				// translators: %s is a link to the cache configuration guide.
				__( 'View our <a href="%s">cache configuration guide</a> for more information.', 'pantheon-advanced-page-cache' ),
				'https://docs.pantheon.io/guides/wordpress-configurations/wordpress-cache-plugin#pantheon-page-cache-plugin-configuration'
			)
		),
		'test' => 'pantheon_edge_cache',
	];

	return $result;
}

/**
 * Clear the max-age compare cache when the max-age is updated.
 *
 * @since 2.0.0
 * @return void
 */
function clear_max_age_compare_cache() {
	delete_transient( 'papc_max_age_compare' );
}

/**
 * Set the default_ttl from the mu-plugin to WEEK_IN_SECONDS if it was saved as 600 seconds.
 *
 * @since 2.0.0
 * @return bool
 */
function set_max_age_to_default() {
	$pantheon_cache = get_option( 'pantheon-cache', [] );
	$pantheon_max_age_updated = get_option( 'pantheon_max_age_updated', false );

	// If we've already done this, bail.
	if ( $pantheon_max_age_updated ) {
		return;
	}

	// If nothing is saved, bail. The default is used automatically.
	if ( ! isset( $pantheon_cache['default_ttl'] ) ) {
		return;
	}

	// Everything beyond this point assumes the max age has been set manually or will be set to the default.
	update_option( 'pantheon_max_age_updated', true );

	// If the default_ttl is not 600, bail.
	if ( 600 !== $pantheon_cache['default_ttl'] ) {
		return;
	}

	// Set the max age. At this point, we should only be here if it was set to 600. We're using the filter here in case someone has overridden the default.
	$pantheon_cache['default_ttl'] = apply_filters( 'pantheon_cache_default_max_age', WEEK_IN_SECONDS );

	// Update the option and set the max_age_updated flag and show the admin notice.
	update_option( 'pantheon-cache', $pantheon_cache );
}

/**
 * Display an admin notice if the max-age was updated.
 *
 * @since 2.0.0
 * @return void
 */
function max_age_updated_admin_notice() {
	// Check if notices should be disabled. This includes if the user is using a version of WordPress that does not support wp_admin_notice.
	if ( apply_filters( 'pantheon_apc_disable_admin_notices', false, __FUNCTION__ ) ) {
		return;
	}

	// Can the user manage options? If not, don't show the notice.
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Check user meta to see if this user has seen this notice before.
	$current_user_id = get_current_user_id();
	$dismissed = get_user_meta( $current_user_id, 'pantheon_max_age_updated_notice', true );
	if ( $dismissed ) {
		return;
	}

	// Check if the max-age was updated.
	$max_age_updated = get_option( 'pantheon_max_age_updated', false );
	if ( ! $max_age_updated ) {
		return;
	}

	// If we got here, this is the _first time_ this user has seen this notice since the option was updated. Show the notice and update the user meta.
	wp_admin_notice(
		sprintf(
			// translators: %1$s is the humanized max-age, %2$d is a link to the Pantheon documentation.
			__( 'The Pantheon GCDN cache max-age has been updated. The previous value was 10 minutes. The new value is %1$s. For more information, refer to the <a href="%2$s">Pantheon documentation</a>.', 'pantheon-advanced-page-cache' ),
			humanized_max_age(),
			'https://docs.pantheon.io/guides/wordpress-configurations/wordpress-cache-plugin'
		),
		[
			'type' => 'info',
			'dismissible' => false,
		]
	);

	if ( ! $max_age_updated ) {
		return;
	}

	// Update the user meta to prevent this notice from showing again after they've seen it once.
	update_user_meta( $current_user_id, 'pantheon_max_age_updated_notice', true );
}
