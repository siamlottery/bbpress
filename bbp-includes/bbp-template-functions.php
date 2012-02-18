<?php

/**
 * bbPress Template Functions
 *
 * @package bbPress
 * @subpackage TemplateFunctions
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * Adds bbPress theme support to any active WordPress theme
 *
 * @since bbPress (r3032)
 *
 * @param string $slug
 * @param string $name Optional. Default null
 * @uses bbp_locate_template()
 * @uses load_template()
 * @uses get_template_part()
 */
function bbp_get_template_part( $slug, $name = null ) {

	do_action( 'get_template_part_' . $slug, $slug, $name );

	$templates = array();
	if ( isset( $name ) )
		$templates[] = $slug . '-' . $name . '.php';

	$templates[] = $slug . '.php';

	return bbp_locate_template( $templates, true, false );
}

/**
 * Retrieve the name of the highest priority template file that exists.
 *
 * Searches in the STYLESHEETPATH before TEMPLATEPATH so that themes which
 * inherit from a parent theme can just overload one file. If the template is
 * not found in either of those, it looks in the theme-compat folder last.
 *
 * @since bbPres (r3618)
 *
 * @param string|array $template_names Template file(s) to search for, in order.
 * @param bool $load If true the template file will be loaded if it is found.
 * @param bool $require_once Whether to require_once or require. Default true.
 *                            Has no effect if $load is false.
 * @return string The template filename if one is located.
 */
function bbp_locate_template( $template_names, $load = false, $require_once = true ) {

	// No file found yet
	$located = false;

	// Try to find a template file
	foreach ( (array) $template_names as $template_name ) {

		// Continue if template is empty
		if ( empty( $template_name ) )
			continue;

		// Check child theme first
		if ( file_exists( STYLESHEETPATH . '/' . $template_name ) ) {
			$located = STYLESHEETPATH . '/' . $template_name;
			break;

		// Check parent theme next
		} else if ( file_exists( TEMPLATEPATH . '/' . $template_name ) ) {
			$located = TEMPLATEPATH . '/' . $template_name;
			break;

		// Check theme compatibility last
		} else if ( file_exists( bbp_get_theme_compat_dir() . '/' . $template_name ) ) {
			$located = bbp_get_theme_compat_dir() . '/' . $template_name;
			break;
		}
	}

	if ( ( true == $load ) && !empty( $located ) )
		load_template( $located, $require_once );

	return $located;
}

/**
 * Retrieve path to a template
 *
 * Used to quickly retrieve the path of a template without including the file
 * extension. It will also check the parent theme and theme-compat theme with
 * the use of {@link bbp_locate_template()}. Allows for more generic template
 * locations without the use of the other get_*_template() functions.
 *
 * @since bbPress (r3629)
 *
 * @param string $type Filename without extension.
 * @param array $templates An optional list of template candidates
 * @uses bbp_set_theme_compat_templates()
 * @uses bbp_locate_template()
 * @uses bbp_set_theme_compat_template()
 * @return string Full path to file.
 */
function bbp_get_query_template( $type, $templates = array() ) {
	$type = preg_replace( '|[^a-z0-9-]+|', '', $type );

	if ( empty( $templates ) )
		$templates = array( "{$type}.php" );

	$templates = apply_filters( "bbp_get_{$type}_template", $templates );
	$templates = bbp_set_theme_compat_templates( $templates );
	$template  = bbp_locate_template( $templates );
	$template  = bbp_set_theme_compat_template( $template );

	return apply_filters( "bbp_{$type}_template", $template );
}

/**
 * Add checks for bbPress conditions to parse_query action
 *
 * If it's a user page, WP_Query::bbp_is_single_user is set to true.
 * If it's a user edit page, WP_Query::bbp_is_single_user_edit is set to true
 * and the the 'wp-admin/includes/user.php' file is included.
 * In addition, on user/user edit pages, WP_Query::home is set to false & query
 * vars 'bbp_user_id' with the displayed user id and 'author_name' with the
 * displayed user's nicename are added.
 *
 * If it's a forum edit, WP_Query::bbp_is_forum_edit is set to true
 * If it's a topic edit, WP_Query::bbp_is_topic_edit is set to true
 * If it's a reply edit, WP_Query::bbp_is_reply_edit is set to true.
 *
 * If it's a view page, WP_Query::bbp_is_view is set to true
 *
 * @since bbPress (r2688)
 *
 * @global bbPress $bbp
 * @param WP_Query $posts_query
 *
 * @uses get_query_var() To get {@link WP_Query} query var
 * @uses is_email() To check if the string is an email
 * @uses get_user_by() To try to get the user by email and nicename
 * @uses WP_User to get the user data
 * @uses WP_Query::set_404() To set a 404 status
 * @uses current_user_can() To check if the current user can edit the user
 * @uses apply_filters() Calls 'enable_edit_any_user_configuration' with true
 * @uses bbp_is_query_name() Check if query name is 'bbp_widget'
 * @uses bbp_get_view_query_args() To get the view query args
 * @uses bbp_get_forum_post_type() To get the forum post type
 * @uses bbp_get_topic_post_type() To get the topic post type
 * @uses bbp_get_reply_post_type() To get the reply post type
 * @uses remove_action() To remove the auto save post revision action
 */
function bbp_parse_query( $posts_query ) {
	global $bbp;

	// Bail if $posts_query is not the main loop
	if ( ! $posts_query->is_main_query() )
		return;

	// Bail if filters are suppressed on this query
	if ( true == $posts_query->get( 'suppress_filters' ) )
		return;

	// Bail if in admin
	if ( is_admin() )
		return;

	// Get query variables
	$bbp_user = $posts_query->get( $bbp->user_id );
	$bbp_view = $posts_query->get( $bbp->view_id );
	$is_edit  = $posts_query->get( $bbp->edit_id );

	// It is a user page - We'll also check if it is user edit
	if ( !empty( $bbp_user ) ) {

		// Not a user_id so try email and slug
		if ( !is_numeric( $bbp_user ) ) {

			// Email was passed
			if ( is_email( $bbp_user ) ) {
				$bbp_user = get_user_by( 'email', $bbp_user );

			// Try nicename
			} else {
				$bbp_user = get_user_by( 'slug', $bbp_user );
			}

			// If we were successful, set to ID
			if ( is_object( $bbp_user ) ) {
				$bbp_user = $bbp_user->ID;
			}
		}

		// Create new user
		$user = new WP_User( $bbp_user );

		// Bail if no user
		if ( !isset( $user ) || empty( $user ) || empty( $user->ID ) ) {
			$posts_query->set_404();
			return;
		}

		/** User Exists *******************************************************/

		// View or edit?
		if ( !empty( $is_edit ) ) {

			// We are editing a profile
			$posts_query->bbp_is_single_user_edit = true;

			// Load the core WordPress contact methods
			if ( !function_exists( '_wp_get_user_contactmethods' ) ) {
				include_once( ABSPATH . 'wp-includes/registration.php' );
			}

			// Load the edit_user functions
			if ( !function_exists( 'edit_user' ) ) {
				require_once( ABSPATH . 'wp-admin/includes/user.php' );
			}

			// Editing a user
			$posts_query->bbp_is_edit = true;

		// We are viewing a profile
		} else {
			$posts_query->bbp_is_single_user = true;
		}

		// Make sure 404 is not set
		$posts_query->is_404  = false;

		// Correct is_home variable
		$posts_query->is_home = false;

		// Set bbp_user_id for future reference
		$posts_query->set( 'bbp_user_id', $user->ID );

		// Set author_name as current user's nicename to get correct posts
		if ( !bbp_is_query_name( 'bbp_widget' ) ) {
			$posts_query->set( 'author_name', $user->user_nicename );
		}

		// Set the displayed user global to this user
		$bbp->displayed_user = $user;

	// View Page
	} elseif ( !empty( $bbp_view ) ) {

		// Check if the view exists by checking if there are query args are set
		$view_args = bbp_get_view_query_args( $bbp_view );

		// Bail if view args is false (view isn't registered)
		if ( false === $view_args ) {
			$posts_query->set_404();
			return;
		}

		// Correct is_home variable
		$posts_query->is_home     = false;

		// We are in a custom topic view
		$posts_query->bbp_is_view = true;

	// Forum/Topic/Reply Edit Page
	} elseif ( !empty( $is_edit ) ) {

		// Get the post type from the main query loop
		$post_type = $posts_query->get( 'post_type' );
		
		// Check which post_type we are editing, if any
		if ( !empty( $post_type ) ) {
			switch( $post_type ) {

				// We are editing a forum
				case bbp_get_forum_post_type() :
					$posts_query->bbp_is_forum_edit = true;
					$posts_query->bbp_is_edit       = true;
					break;

				// We are editing a topic
				case bbp_get_topic_post_type() :
					$posts_query->bbp_is_topic_edit = true;
					$posts_query->bbp_is_edit       = true;
					break;

				// We are editing a reply
				case bbp_get_reply_post_type() :
					$posts_query->bbp_is_reply_edit = true;
					$posts_query->bbp_is_edit       = true;
					break;
			}

		// We are editing a topic tag
		} elseif ( bbp_is_topic_tag() ) {
			$posts_query->bbp_is_topic_tag_edit = true;
			$posts_query->bbp_is_edit           = true;
		}

		// We save post revisions on our own
		remove_action( 'pre_post_update', 'wp_save_post_revision' );

	// Topic tag page
	} elseif ( bbp_is_topic_tag() ) {
		$posts_query->set( 'bbp_topic_tag',  get_query_var( 'term' )   );
		$posts_query->set( 'post_type',      bbp_get_topic_post_type() );
		$posts_query->set( 'posts_per_page', bbp_get_topics_per_page() );
	}
}

?>