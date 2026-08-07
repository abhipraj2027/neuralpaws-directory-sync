<?php
/**
 * Archive template for the AI Tools directory (post type archive + tool_category terms).
 * Server-rendered filters (real <a href> links, not JS-only) so every
 * filtered view is its own crawlable, indexable URL — this is the single
 * biggest SEO lever for a directory: category and pricing combinations
 * become their own long-tail-ranking pages instead of hidden JS states.
 */

get_header();

$current_cat    = is_tax( 'tool_category' ) ? get_queried_object() : null;
$current_pricing = isset( $_GET['pricing'] ) ? sanitize_title( $_GET['pricing'] ) : '';
$sort           = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'votes';

$all_categories = get_terms( array( 'taxonomy' => 'tool_category', 'hide_empty' => true ) );
$all_pricing    = get_terms( array( 'taxonomy' => 'tool_pricing', 'hide_empty' => true ) );

$paged = max( 1, get_query_var( 'paged' ) ?: get_query_var( 'page' ) ?: 1 );

$args = array(
	'post_type'      => 'ai_tool',
	'post_status'    => 'publish',
	'posts_per_page' => 24,
	'paged'          => $paged,
);

if ( $current_cat ) {
	$args['tax_query'][] = array(
		'taxonomy' => 'tool_category',
		'field'    => 'term_id',
		'terms'    => $current_cat->term_id,
	);
}
if ( $current_pricing ) {
	$args['tax_query'][] = array(
		'taxonomy' => 'tool_pricing',
		'field'    => 'slug',
		'terms'    => $current_pricing,
	);
}

switch ( $sort ) {
	case 'newest':
		$args['orderby'] = 'date';
		$args['order']   = 'DESC';
		break;
	case 'name':
		$args['orderby'] = 'title';
		$args['order']   = 'ASC';
		break;
	case 'votes':
	default:
		$args['meta_key'] = '_tool_ph_votes';
		$args['orderby']  = 'meta_value_num';
		$args['order']    = 'DESC';
		break;
}

$q = new WP_Query( $args );

$page_title = $current_cat ? $current_cat->name . ' AI Tools' : 'AI Tools Directory';
$page_desc  = $current_cat && $current_cat->description
	? $current_cat->description
	: 'Browse ' . $q->found_posts . ' AI tools, updated daily from new launches. Filter by category and pricing to find the right tool fast.';
?>

<div class="np-directory">
  <style>
    .np-directory{max-width:1200px;margin:0 auto;padding:0 20px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1A1D2B}
    .np-dir-hero{padding:40px 0 24px;border-bottom:1px solid #ECEDF3;margin-bottom:24px}
    .np-dir-hero h1{font-size:32px;font-weight:800;margin:0 0 8px}
    .np-dir-hero p{color:#4A4E69;font-size:16px;max-width:70ch;margin:0}
    .np-dir-layout{display:grid;grid-template-columns:220px 1fr;gap:32px;align-items:start}
    .np-dir-filters{position:sticky;top:20px;border:1px solid #ECEDF3;border-radius:12px;padding:18px}
    .np-dir-filters h3{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#8E92A8;margin:0 0 10px}
    .np-dir-filters ul{list-style:none;margin:0 0 20px;padding:0}
    .np-dir-filters li{margin:4px 0}
    .np-dir-filters a{color:#4A4E69;text-decoration:none;font-size:14.5px;display:flex;justify-content:space-between}
    .np-dir-filters a.active,.np-dir-filters a:hover{color:#6d28d9;font-weight:600}
    .np-dir-filters .count{color:#9ca3af;font-size:12.5px}
    .np-dir-toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;font-size:14px;color:#4A4E69}
    .np-dir-toolbar select{border:1px solid #ECEDF3;border-radius:8px;padding:6px 10px;font-size:14px}
    .np-dir-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px}
    .np-tool-card{border:1px solid #ECEDF3;border-radius:12px;padding:18px;text-decoration:none;color:inherit;display:flex;flex-direction:column;gap:8px;transition:box-shadow .15s,transform .15s}
    .np-tool-card:hover{box-shadow:0 8px 20px rgba(0,0,0,.06);transform:translateY(-2px)}
    .np-tool-top{display:flex;align-items:center;gap:10px}
    .np-tool-logo{width:40px;height:40px;border-radius:8px;object-fit:cover;background:#F0ECFF;flex-shrink:0}
    .np-tool-name{font-weight:700;font-size:16px;color:#1A1D2B}
    .np-tool-tagline{font-size:13.5px;color:#4A4E69;line-height:1.5;flex:1}
    .np-tool-meta{display:flex;gap:8px;font-size:11.5px;color:#8E92A8;align-items:center}
    .np-tool-badge{background:#F0ECFF;color:#6d28d9;padding:2px 8px;border-radius:20px;font-weight:600}
    .np-dir-pagination{display:flex;gap:8px;justify-content:center;margin:36px 0}
    .np-dir-pagination a,.np-dir-pagination span{padding:8px 14px;border:1px solid #ECEDF3;border-radius:8px;text-decoration:none;color:#4A4E69;font-size:14px}
    .np-dir-pagination .current{background:#6d28d9;color:#fff;border-color:#6d28d9}
    @media(max-width:800px){.np-dir-layout{grid-template-columns:1fr}.np-dir-filters{position:static}}
  </style>

  <div class="np-dir-hero">
    <h1><?php echo esc_html( $page_title ); ?></h1>
    <p><?php echo esc_html( $page_desc ); ?></p>
  </div>

  <div class="np-dir-layout">
    <aside class="np-dir-filters">
      <h3>Category</h3>
      <ul>
        <li><a href="<?php echo esc_url( get_post_type_archive_link( 'ai_tool' ) ); ?>" class="<?php echo ! $current_cat ? 'active' : ''; ?>">All categories</a></li>
        <?php foreach ( $all_categories as $cat ) : ?>
        <li>
          <a href="<?php echo esc_url( get_term_link( $cat ) ); ?>" class="<?php echo ( $current_cat && $current_cat->term_id === $cat->term_id ) ? 'active' : ''; ?>">
            <span><?php echo esc_html( $cat->name ); ?></span>
            <span class="count"><?php echo (int) $cat->count; ?></span>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>

      <h3>Pricing</h3>
      <ul>
        <li><a href="<?php echo esc_url( remove_query_arg( 'pricing' ) ); ?>" class="<?php echo ! $current_pricing ? 'active' : ''; ?>">All pricing</a></li>
        <?php foreach ( $all_pricing as $pr ) : ?>
        <li>
          <a href="<?php echo esc_url( add_query_arg( 'pricing', $pr->slug ) ); ?>" class="<?php echo ( $current_pricing === $pr->slug ) ? 'active' : ''; ?>">
            <span><?php echo esc_html( $pr->name ); ?></span>
            <span class="count"><?php echo (int) $pr->count; ?></span>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </aside>

    <div>
      <div class="np-dir-toolbar">
        <span><?php echo (int) $q->found_posts; ?> tools</span>
        <form method="get">
          <?php if ( $current_pricing ) : ?><input type="hidden" name="pricing" value="<?php echo esc_attr( $current_pricing ); ?>"><?php endif; ?>
          <label>
            Sort:
            <select name="sort" onchange="this.form.submit()">
              <option value="votes" <?php selected( $sort, 'votes' ); ?>>Most popular</option>
              <option value="newest" <?php selected( $sort, 'newest' ); ?>>Newest</option>
              <option value="name" <?php selected( $sort, 'name' ); ?>>A–Z</option>
            </select>
          </label>
        </form>
      </div>

      <div class="np-dir-grid">
        <?php while ( $q->have_posts() ) : $q->the_post();
          $tagline = get_post_meta( get_the_ID(), '_tool_tagline', true );
          $votes   = get_post_meta( get_the_ID(), '_tool_ph_votes', true );
          $pricing_terms = get_the_terms( get_the_ID(), 'tool_pricing' );
          $pricing_label = ! empty( $pricing_terms ) && ! is_wp_error( $pricing_terms ) ? $pricing_terms[0]->name : '';
        ?>
        <a href="<?php the_permalink(); ?>" class="np-tool-card">
          <div class="np-tool-top">
            <?php if ( has_post_thumbnail() ) : ?>
              <?php the_post_thumbnail( 'thumbnail', array( 'class' => 'np-tool-logo', 'loading' => 'lazy', 'alt' => get_the_title() . ' logo' ) ); ?>
            <?php else : ?>
              <div class="np-tool-logo"></div>
            <?php endif; ?>
            <span class="np-tool-name"><?php the_title(); ?></span>
          </div>
          <div class="np-tool-tagline"><?php echo esc_html( $tagline ?: get_the_excerpt() ); ?></div>
          <div class="np-tool-meta">
            <?php if ( $pricing_label ) : ?><span class="np-tool-badge"><?php echo esc_html( $pricing_label ); ?></span><?php endif; ?>
            <?php if ( $votes ) : ?><span>&#9650; <?php echo (int) $votes; ?> on PH</span><?php endif; ?>
          </div>
        </a>
        <?php endwhile; wp_reset_postdata(); ?>
      </div>

      <div class="np-dir-pagination">
        <?php
        echo paginate_links( array(
          'total'     => $q->max_num_pages,
          'current'   => $paged,
          'prev_text' => '&larr; Prev',
          'next_text' => 'Next &rarr;',
        ) );
        ?>
      </div>
    </div>
  </div>
</div>

<?php get_footer(); ?>
