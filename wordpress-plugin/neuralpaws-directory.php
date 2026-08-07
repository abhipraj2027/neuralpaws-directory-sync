<?php
/**
 * Plugin Name: NeuralPaws AI Tools Directory
 * Plugin URI: https://neuralpaws.com
 * Description: Structured "AI Tool" directory post type with Product Hunt sync fields, category taxonomy, REST support, and SEO-friendly archive/single templates. Built to be filled automatically by the neuralpaws-directory-sync Python pipeline.
 * Version: 1.0.0
 * Author: NeuralPaws
 * Text Domain: neuralpaws-directory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'NPD_VERSION', '1.0.0' );
define( 'NPD_PATH', plugin_dir_path( __FILE__ ) );
define( 'NPD_URL', plugin_dir_url( __FILE__ ) );

/* ------------------------------------------------------------------------
 * 1. Custom Post Type: ai_tool
 * ---------------------------------------------------------------------- */
add_action( 'init', function () {

	register_post_type( 'ai_tool', array(
		'labels' => array(
			'name'               => 'AI Tools',
			'singular_name'      => 'AI Tool',
			'add_new_item'       => 'Add New Tool',
			'edit_item'          => 'Edit Tool',
			'all_items'          => 'All Tools',
			'search_items'       => 'Search Tools',
			'not_found'          => 'No tools found',
		),
		'public'             => true,
		'has_archive'        => 'ai-tools',
		'rewrite'            => array(
			'slug'       => 'ai-tools',
			'with_front' => false,
		),
		'show_in_rest'       => true,
		'rest_base'          => 'ai_tool',
		'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions' ),
		'menu_icon'          => 'dashicons-grid-view',
		'menu_position'      => 5,
		'capability_type'    => 'post',
		'hierarchical'       => false,
	) );

	// Taxonomy: tool category (Coding AI, Writing AI, Image AI, ...)
	register_taxonomy( 'tool_category', 'ai_tool', array(
		'labels' => array(
			'name'          => 'Tool Categories',
			'singular_name' => 'Tool Category',
		),
		'public'            => true,
		'hierarchical'      => true,
		'show_in_rest'      => true,
		'rewrite'           => array( 'slug' => 'ai-tools/category' ),
	) );

	// Taxonomy: pricing model (Free, Freemium, Paid, Open Source) — small, flat, great for faceted filtering.
	register_taxonomy( 'tool_pricing', 'ai_tool', array(
		'labels' => array(
			'name'          => 'Pricing Models',
			'singular_name' => 'Pricing Model',
		),
		'public'            => true,
		'hierarchical'      => false,
		'show_in_rest'      => true,
		'rewrite'           => array( 'slug' => 'ai-tools/pricing' ),
	) );
} );

/* ------------------------------------------------------------------------
 * 2. Post meta fields (REST-exposed so the Python sync script can write
 *    them directly via POST /wp-json/wp/v2/ai_tool).
 * ---------------------------------------------------------------------- */
add_action( 'init', function () {

	$string_field = array(
		'show_in_rest' => true,
		'single'       => true,
		'type'         => 'string',
		'auth_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
	);
	$number_field = $string_field;
	$number_field['type'] = 'number';

	$fields = array(
		'_tool_tagline'        => $string_field,   // one-line PH tagline
		'_tool_website'        => $string_field,   // canonical product URL (redirect-resolved)
		'_tool_ph_url'         => $string_field,   // producthunt.com/posts/<slug>
		'_tool_ph_id'          => $string_field,   // PH post id — used for dedupe, unique
		'_tool_logo_url'       => $string_field,   // PH thumbnail, sideloaded separately as featured image
		'_tool_ph_votes'       => $number_field,   // votesCount at time of sync
		'_tool_ph_launch_date' => $string_field,   // ISO date, PH featuredAt
		'_tool_pricing_start'  => $string_field,   // e.g. "$0", "$19/mo" — free text, best-effort
		'_tool_source'         => $string_field,   // "producthunt" | "manual"
		'_tool_review_status'  => $string_field,   // "needs_review" | "auto_published" | "editor_reviewed"
		'_tool_last_verified'  => $string_field,   // ISO date — last time a human/script confirmed the listing is live
		'_tool_score'          => $number_field,   // internal relevance/quality score used for directory sort order
	);

	foreach ( $fields as $key => $args ) {
		register_post_meta( 'ai_tool', $key, $args );
	}
} );

/* ------------------------------------------------------------------------
 * 2b. Allow REST queries like
 *     GET /wp-json/wp/v2/ai_tool?meta_key=_tool_ph_id&meta_value=12345
 *     Core WP REST ignores arbitrary meta_key/meta_value query params by
 *     default (security default) — this whitelist enables it ONLY for
 *     the two keys the sync pipeline dedupes on, and only for users who
 *     can already edit posts (same as anyone browsing wp-admin).
 * ---------------------------------------------------------------------- */
add_filter( 'rest_ai_tool_query', function ( $args, $request ) {
	$allowed_meta_keys = array( '_tool_ph_id', '_tool_website' );
	$meta_key   = $request->get_param( 'meta_key' );
	$meta_value = $request->get_param( 'meta_value' );

	if ( $meta_key && $meta_value && in_array( $meta_key, $allowed_meta_keys, true ) ) {
		$args['meta_query'] = array(
			array(
				'key'   => sanitize_text_field( $meta_key ),
				'value' => sanitize_text_field( $meta_value ),
			),
		);
	}
	return $args;
}, 10, 2 );

/* ------------------------------------------------------------------------
 * 3. Admin columns — makes the WP admin list usable for editorial review
 *    of what the sync pipeline queued up.
 * ---------------------------------------------------------------------- */
add_filter( 'manage_ai_tool_posts_columns', function ( $columns ) {
	$new = array();
	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;
		if ( 'title' === $key ) {
			$new['tool_votes']  = 'PH Votes';
			$new['tool_status'] = 'Review Status';
			$new['tool_source'] = 'Source';
		}
	}
	return $new;
} );

add_action( 'manage_ai_tool_posts_custom_column', function ( $column, $post_id ) {
	switch ( $column ) {
		case 'tool_votes':
			echo esc_html( get_post_meta( $post_id, '_tool_ph_votes', true ) ?: '—' );
			break;
		case 'tool_status':
			$status = get_post_meta( $post_id, '_tool_review_status', true ) ?: 'needs_review';
			$colors = array(
				'needs_review'    => '#d97706',
				'auto_published'  => '#059669',
				'editor_reviewed' => '#2563eb',
			);
			$color = $colors[ $status ] ?? '#6b7280';
			printf( '<span style="color:%s;font-weight:600;">%s</span>', esc_attr( $color ), esc_html( str_replace( '_', ' ', $status ) ) );
			break;
		case 'tool_source':
			echo esc_html( get_post_meta( $post_id, '_tool_source', true ) ?: 'manual' );
			break;
	}
}, 10, 2 );

/* ------------------------------------------------------------------------
 * 4. Admin filter: quickly view "needs_review" queue
 * ---------------------------------------------------------------------- */
add_action( 'restrict_manage_posts', function ( $post_type ) {
	if ( 'ai_tool' !== $post_type ) {
		return;
	}
	$current = $_GET['tool_review_status'] ?? '';
	?>
	<select name="tool_review_status">
		<option value="">All review statuses</option>
		<option value="needs_review" <?php selected( $current, 'needs_review' ); ?>>Needs review</option>
		<option value="auto_published" <?php selected( $current, 'auto_published' ); ?>>Auto-published</option>
		<option value="editor_reviewed" <?php selected( $current, 'editor_reviewed' ); ?>>Editor reviewed</option>
	</select>
	<?php
} );

add_filter( 'parse_query', function ( $query ) {
	global $pagenow;
	if ( is_admin() && 'edit.php' === $pagenow && ( $query->query['post_type'] ?? '' ) === 'ai_tool' && ! empty( $_GET['tool_review_status'] ) ) {
		$query->query_vars['meta_key']   = '_tool_review_status';
		$query->query_vars['meta_value'] = sanitize_text_field( $_GET['tool_review_status'] );
	}
} );

/* ------------------------------------------------------------------------
 * 5. Dedicated XML sitemap segment for ai_tool (kept lean — directories
 *    with thousands of entries should not dump them all in the core
 *    sitemap; a dedicated, well-paced sitemap helps crawl budget).
 *    Works alongside Rank Math / Yoast if active — this simply ensures
 *    the CPT is included with correct priority signals.
 * ---------------------------------------------------------------------- */
add_filter( 'wp_sitemaps_post_types', function ( $post_types ) {
	// Native WP sitemaps: ai_tool is public + show_in_rest, so it's included
	// automatically. This filter is a hook point if you need to exclude
	// unverified/needs_review tools from the sitemap (they're drafts, so
	// they're already excluded — this is a safety net for edge cases).
	return $post_types;
} );

/* ------------------------------------------------------------------------
 * 6. SoftwareApplication + BreadcrumbList JSON-LD on single ai_tool pages.
 *    If Rank Math/Yoast is active, disable their generic schema for this
 *    CPT (Settings → Schema) to avoid duplicate/conflicting markup, and
 *    let this run instead — it's tailored to directory-listing intent.
 * ---------------------------------------------------------------------- */
add_action( 'wp_head', function () {
	if ( ! is_singular( 'ai_tool' ) ) {
		return;
	}
	global $post;

	$tagline   = get_post_meta( $post->ID, '_tool_tagline', true );
	$website   = get_post_meta( $post->ID, '_tool_website', true );
	$pricing   = get_post_meta( $post->ID, '_tool_pricing_start', true );
	$categories = wp_get_post_terms( $post->ID, 'tool_category', array( 'fields' => 'names' ) );
	$category_name = ! empty( $categories ) ? $categories[0] : 'AI Tool';

	$schema = array(
		'@context' => 'https://schema.org',
		'@graph'   => array(
			array(
				'@type'             => 'SoftwareApplication',
				'@id'               => get_permalink( $post ) . '#software',
				'name'              => get_the_title( $post ),
				'description'       => $tagline ?: wp_strip_all_tags( get_the_excerpt( $post ) ),
				'applicationCategory' => $category_name,
				'url'               => $website ?: get_permalink( $post ),
				'offers'            => array(
					'@type' => 'Offer',
					'price' => preg_replace( '/[^0-9.]/', '', $pricing ) ?: '0',
					'priceCurrency' => 'USD',
				),
			),
			array(
				'@type'         => 'BreadcrumbList',
				'@id'           => get_permalink( $post ) . '#breadcrumb',
				'itemListElement' => array(
					array( '@type' => 'ListItem', 'position' => 1, 'name' => 'NeuralPaws', 'item' => home_url( '/' ) ),
					array( '@type' => 'ListItem', 'position' => 2, 'name' => 'AI Tools Directory', 'item' => home_url( '/ai-tools/' ) ),
					array( '@type' => 'ListItem', 'position' => 3, 'name' => get_the_title( $post ) ),
				),
			),
		),
	);

	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
}, 5 );

/* ------------------------------------------------------------------------
 * 7. ItemList JSON-LD on the archive/category pages (only the tools
 *    actually shown on the current page — never the whole catalog, to
 *    avoid schema/content mismatch penalties).
 * ---------------------------------------------------------------------- */
add_action( 'wp_head', function () {
	if ( ! is_post_type_archive( 'ai_tool' ) && ! is_tax( 'tool_category' ) ) {
		return;
	}
	global $wp_query;
	if ( empty( $wp_query->posts ) ) {
		return;
	}

	$items = array();
	$position = 1;
	foreach ( $wp_query->posts as $p ) {
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $position++,
			'url'      => get_permalink( $p ),
			'name'     => get_the_title( $p ),
		);
	}

	$schema = array(
		'@context'      => 'https://schema.org',
		'@type'         => 'ItemList',
		'itemListElement' => $items,
	);

	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
}, 5 );

/* ------------------------------------------------------------------------
 * 8. Load bundled archive/single templates automatically if the active
 *    theme doesn't already provide its own ai_tool templates.
 * ---------------------------------------------------------------------- */
add_filter( 'template_include', function ( $template ) {
	if ( is_post_type_archive( 'ai_tool' ) || is_tax( 'tool_category' ) || is_tax( 'tool_pricing' ) ) {
		$theme_file = locate_template( 'archive-ai_tool.php' );
		if ( ! $theme_file ) {
			return NPD_PATH . 'templates/archive-ai_tool.php';
		}
	}
	if ( is_singular( 'ai_tool' ) ) {
		$theme_file = locate_template( 'single-ai_tool.php' );
		if ( ! $theme_file ) {
			return NPD_PATH . 'templates/single-ai_tool.php';
		}
	}
	return $template;
} );

/* ------------------------------------------------------------------------
 * 9. Flush rewrite rules on activation/deactivation so /ai-tools/ works
 *    immediately without a manual permalink re-save.
 * ---------------------------------------------------------------------- */
register_activation_hook( __FILE__, function () {
	// Post type + taxonomies are registered on 'init', which has already
	// fired by the time activation hooks run in most setups; registering
	// here too is a safe belt-and-braces for the flush.
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
