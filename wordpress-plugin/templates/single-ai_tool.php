<?php
/**
 * Single AI Tool page. This is intentionally NOT a thin auto-generated
 * page: it pulls PH data (tagline, votes, launch date, pricing) but
 * reserves the main content area for editorial/AI-assisted unique
 * commentary (get_the_content), which the sync pipeline seeds with a
 * short auto-summary that editors are expected to expand before a tool
 * is marked "editor_reviewed". Thin, identical-shaped pages with no
 * unique text are the #1 reason auto-built directories get filtered
 * out of search results — this template's structure forces at least
 * some unique surface area per tool (see docs/seo-strategy.md).
 */

get_header();

while ( have_posts() ) : the_post();

$id = get_the_ID();
$tagline    = get_post_meta( $id, '_tool_tagline', true );
$website    = get_post_meta( $id, '_tool_website', true );
$ph_url     = get_post_meta( $id, '_tool_ph_url', true );
$votes      = get_post_meta( $id, '_tool_ph_votes', true );
$launch     = get_post_meta( $id, '_tool_ph_launch_date', true );
$pricing_start = get_post_meta( $id, '_tool_pricing_start', true );
$last_verified = get_post_meta( $id, '_tool_last_verified', true );
$categories = get_the_terms( $id, 'tool_category' );
$pricing_terms = get_the_terms( $id, 'tool_pricing' );
?>

<div class="np-tool-page" style="max-width:820px;margin:0 auto;padding:40px 20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1A1D2B;">

  <nav style="font-size:13px;color:#8E92A8;margin-bottom:20px;">
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="color:#6d28d9;text-decoration:none;">NeuralPaws</a>
    &rsaquo; <a href="<?php echo esc_url( get_post_type_archive_link( 'ai_tool' ) ); ?>" style="color:#6d28d9;text-decoration:none;">AI Tools</a>
    <?php if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) : ?>
      &rsaquo; <a href="<?php echo esc_url( get_term_link( $categories[0] ) ); ?>" style="color:#6d28d9;text-decoration:none;"><?php echo esc_html( $categories[0]->name ); ?></a>
    <?php endif; ?>
    &rsaquo; <?php the_title(); ?>
  </nav>

  <header style="display:flex;gap:16px;align-items:center;margin-bottom:8px;">
    <?php if ( has_post_thumbnail() ) : ?>
      <?php the_post_thumbnail( 'medium', array( 'style' => 'width:64px;height:64px;border-radius:14px;object-fit:cover;', 'alt' => get_the_title() . ' logo' ) ); ?>
    <?php endif; ?>
    <div>
      <h1 style="font-size:30px;font-weight:800;margin:0;"><?php the_title(); ?></h1>
      <?php if ( $tagline ) : ?><p style="color:#4A4E69;font-size:16px;margin:4px 0 0;"><?php echo esc_html( $tagline ); ?></p><?php endif; ?>
    </div>
  </header>

  <div style="display:flex;flex-wrap:wrap;gap:10px;margin:18px 0;font-size:13.5px;">
    <?php if ( $votes ) : ?><span style="background:#F0ECFF;color:#6d28d9;padding:5px 12px;border-radius:20px;font-weight:600;">&#9650; <?php echo (int) $votes; ?> Product Hunt votes</span><?php endif; ?>
    <?php if ( ! empty( $pricing_terms ) && ! is_wp_error( $pricing_terms ) ) : ?><span style="background:#ecfdf5;color:#059669;padding:5px 12px;border-radius:20px;font-weight:600;"><?php echo esc_html( $pricing_terms[0]->name ); ?><?php echo $pricing_start ? ' &middot; ' . esc_html( $pricing_start ) : ''; ?></span><?php endif; ?>
    <?php if ( $launch ) : ?><span style="background:#f9fafb;color:#4A4E69;padding:5px 12px;border-radius:20px;">Launched <?php echo esc_html( date_i18n( 'M j, Y', strtotime( $launch ) ) ); ?></span><?php endif; ?>
  </div>

  <?php if ( $website ) : ?>
  <a href="<?php echo esc_url( $website ); ?>" target="_blank" rel="nofollow noopener" style="display:inline-block;background:#6d28d9;color:#fff;padding:12px 24px;border-radius:10px;text-decoration:none;font-weight:700;margin-bottom:28px;">
    Visit <?php the_title(); ?> &rarr;
  </a>
  <?php endif; ?>

  <article style="font-size:16px;line-height:1.75;">
    <?php the_content(); ?>
  </article>

  <?php if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) : ?>
  <div style="margin-top:28px;padding-top:20px;border-top:1px solid #ECEDF3;font-size:14px;color:#4A4E69;">
    Categories:
    <?php foreach ( $categories as $cat ) : ?>
      <a href="<?php echo esc_url( get_term_link( $cat ) ); ?>" style="color:#6d28d9;text-decoration:none;margin-right:8px;"><?php echo esc_html( $cat->name ); ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div style="margin-top:12px;font-size:12.5px;color:#9ca3af;">
    <?php if ( $ph_url ) : ?><a href="<?php echo esc_url( $ph_url ); ?>" target="_blank" rel="nofollow noopener" style="color:#9ca3af;">View on Product Hunt</a> &middot; <?php endif; ?>
    Listing last verified: <?php echo esc_html( $last_verified ? date_i18n( 'M j, Y', strtotime( $last_verified ) ) : 'pending review' ); ?>
  </div>

  <?php
  // Related tools — same primary category, excluding self. Real internal
  // links between directory entries are one of the strongest on-site SEO
  // levers for a catalog site (spreads link equity, keeps crawl depth low).
  if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) :
    $related = new WP_Query( array(
      'post_type'      => 'ai_tool',
      'post_status'    => 'publish',
      'posts_per_page' => 4,
      'post__not_in'   => array( $id ),
      'tax_query'      => array( array(
        'taxonomy' => 'tool_category',
        'field'    => 'term_id',
        'terms'    => $categories[0]->term_id,
      ) ),
    ) );
    if ( $related->have_posts() ) :
  ?>
  <div style="margin-top:36px;">
    <h2 style="font-size:20px;font-weight:800;margin-bottom:14px;">More <?php echo esc_html( $categories[0]->name ); ?> tools</h2>
    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;">
      <?php while ( $related->have_posts() ) : $related->the_post(); ?>
        <a href="<?php the_permalink(); ?>" style="display:block;border:1px solid #ECEDF3;border-radius:10px;padding:14px;text-decoration:none;color:#1A1D2B;font-weight:600;font-size:14.5px;"><?php the_title(); ?></a>
      <?php endwhile; wp_reset_postdata(); ?>
    </div>
  </div>
  <?php endif; endif; ?>

</div>

<?php endwhile; get_footer(); ?>
