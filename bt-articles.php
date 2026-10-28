<?php
/**
 * Plugin Name: BT Articles
 * Description: Arabic مقالات section (theater fit-out, acoustics, lighting). Imports batch pages, media, nav link.
 * Version: 1.0.0
 * Author: BoxTech
 * Text Domain: bt-articles
 */
if (!defined('ABSPATH')) exit;

define('BT_ART_DIR', plugin_dir_path(__FILE__));
define('BT_ART_URL', plugin_dir_url(__FILE__));
define('BT_ART_VER', '1.0.0');

register_activation_hook(__FILE__, 'bt_art_activate');
add_action('admin_init', 'bt_art_maybe_import');
add_action('plugins_loaded', 'bt_art_boot');

function bt_art_activate() {
  update_option('bt_art_do_import', 1, false);
  flush_rewrite_rules();
}

function bt_art_auto_import_once() {
  if (get_option('bt_art_imported')) return;
  if (get_transient('bt_art_importing')) return;
  set_transient('bt_art_importing', 1, 600);
  bt_art_import_batch();
  delete_transient('bt_art_importing');
}

function bt_art_maybe_import() {
  if (!get_option('bt_art_do_import')) return;
  if (!current_user_can('activate_plugins') && !defined('WP_CLI')) {
    // Allow CLI or first admin hit after activate
  }
  delete_option('bt_art_do_import');
  bt_art_import_batch();
}

function bt_art_boot() {
  add_action('init', 'bt_art_auto_import_once', 5);
  add_action('template_redirect', 'bt_art_ob_start', 0);
  add_action('wp_head', 'bt_art_jsonld_passthrough', 5);
  // Ensure Rank Math / core sitemaps pick up new pages (pages are standard).
  add_filter('wp_sitemaps_posts_query_args', function ($args, $post_type) {
    return $args;
  }, 10, 2);
}

/** Inject «مقالات» into header/footer chrome without removing existing links. */
function bt_art_ob_start() {
  if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;
  ob_start('bt_art_ob_inject');
}

function bt_art_ob_inject($html) {
  if ($html === '') return $html;
  // Skip if a dedicated nav/footer link to /ar/articles/ already exists
  if (preg_match('#<a[^>]+href="[^"]*/ar/articles/?"[^>]*>\s*مقالات\s*</a>#u', $html)) {
    return $html;
  }
  $link = '<a class="hover:text-bronze" href="' . esc_url(home_url('/ar/articles/')) . '">مقالات</a>';
  $footer_li = '<li><a href="' . esc_url(home_url('/ar/articles/')) . '">مقالات</a></li>';
  // After المدونة / Journal link in nav (once)
  $html2 = preg_replace(
    '#(<a[^>]+href="[^"]*/ar/journal/?"[^>]*>المدونة</a>)#u',
    '$1' . $link,
    $html,
    1,
    $c1
  );
  if (!$c1) {
    $html2 = preg_replace(
      '#(<a[^>]+href="[^"]*/ar/journal[^"]*"[^>]*>المدونة</a>)#u',
      '$1' . $link,
      $html,
      1,
      $c1
    );
  }
  if ($c1) $html = $html2;
  // Footer: after المدونة list item (once) — only if footer link missing
  if (!preg_match('#<li>\s*<a[^>]+href="[^"]*/ar/articles/?"[^>]*>\s*مقالات\s*</a>\s*</li>#u', $html)) {
    $html = preg_replace(
      '#(<li>\s*<a[^>]+href="[^"]*/ar/journal/?"[^>]*>المدونة</a>\s*</li>)#u',
      '$1' . $footer_li,
      $html,
      1
    );
  }
  return $html;
}

function bt_art_jsonld_passthrough() {
  // JSON-LD is embedded in post_content; nothing extra.
}

function bt_art_find_page($meta_key, $meta_value) {
  $q = get_posts([
    'post_type' => 'page',
    'post_status' => 'any',
    'meta_key' => $meta_key,
    'meta_value' => $meta_value,
    'fields' => 'ids',
    'numberposts' => 1,
    'lang' => '',
  ]);
  return $q ? (int) $q[0] : 0;
}

function bt_art_sideload($abs, $parent = 0) {
  require_once ABSPATH . 'wp-admin/includes/media.php';
  require_once ABSPATH . 'wp-admin/includes/file.php';
  require_once ABSPATH . 'wp-admin/includes/image.php';
  $legacy = '_bt_art_media:' . basename($abs);
  $existing = get_posts([
    'post_type' => 'attachment',
    'post_status' => 'any',
    'meta_key' => '_bt_art_media',
    'meta_value' => basename($abs),
    'fields' => 'ids',
    'numberposts' => 1,
  ]);
  if ($existing) return (int) $existing[0];
  if (!is_file($abs)) return 0;
  $tmp = wp_tempnam($abs);
  copy($abs, $tmp);
  $id = media_handle_sideload([
    'name' => basename($abs),
    'tmp_name' => $tmp,
  ], $parent);
  if (is_wp_error($id)) {
    @unlink($tmp);
    return 0;
  }
  update_post_meta($id, '_bt_art_media', basename($abs));
  return (int) $id;
}

function bt_art_import_batch() {
  $manifest_file = BT_ART_DIR . 'data/manifest.json';
  if (!is_file($manifest_file)) return;
  $manifest = json_decode(file_get_contents($manifest_file), true);
  if (!is_array($manifest)) return;

  // Parent index
  $index_html = file_get_contents(BT_ART_DIR . 'data/index.html');
  $parent_id = bt_art_find_page('_bt_path', 'articles');
  $parent_data = [
    'ID' => $parent_id,
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_name' => 'articles',
    'post_title' => 'مقالات',
    'post_excerpt' => 'مقالات عن تجهيز المسارح والعزل الصوتي والإضاءة',
    'post_content' => $index_html,
  ];
  if ($parent_id) update_post_meta($parent_id, '_bt_force_slug', 1);
  $parent_id = wp_insert_post(wp_slash($parent_data), true);
  if (is_wp_error($parent_id)) return;
  update_post_meta($parent_id, '_bt_force_slug', 1);
  update_post_meta($parent_id, '_bt_path', 'articles');
  update_post_meta($parent_id, '_bt_section', 'articles-index');
  update_post_meta($parent_id, 'rank_math_title', 'مقالات — تجهيز المسارح والعزل الصوتي والإضاءة | بوكس تيك');
  update_post_meta($parent_id, 'rank_math_description', 'مقالات مهنية عن تجهيز المسارح والعزل الصوتي وإضاءة القاعات.');
  if (function_exists('pll_set_post_language')) pll_set_post_language($parent_id, 'ar');
  // Ensure URI /ar/articles/ — Polylang prefixes language
  if (get_post_field('post_name', $parent_id) !== 'articles') {
    wp_update_post(['ID' => $parent_id, 'post_name' => 'articles']);
  }

  $n = 0;
  foreach ($manifest as $a) {
    $html_file = BT_ART_DIR . 'data/' . $a['file'];
    if (!is_file($html_file)) continue;
    $content = file_get_contents($html_file);

    // Sideload photo
    $media_abs = BT_ART_DIR . 'media/' . $a['photo'];
    $aid = bt_art_sideload($media_abs, 0);
    if ($aid) {
      $url = wp_get_attachment_url($aid);
      $content = str_replace('/ar/articles/media/' . $a['photo'], $url, $content);
      $content = str_replace($a['photo'], basename($url) ? $a['photo'] : $a['photo'], $content);
      // replace src path variants
      $content = preg_replace(
        '#src="(?:/ar/articles/media/)?' . preg_quote($a['photo'], '#') . '"#',
        'src="' . esc_url($url) . '"',
        $content
      );
    }

    $old = '/ar/articles/' . $a['slug'] . '/';
    $id = bt_art_find_page('_bt_old_url', $old);
    // Sort key: newest first under ascending strcmp → invert date
    $ymd = (int) str_replace('-', '', $a['date']);
    $sort = sprintf('articles/%08d-%s', 99999999 - $ymd, $a['slug']);

    $data = [
      'ID' => $id,
      'post_type' => 'page',
      'post_status' => 'publish',
      'post_parent' => $parent_id,
      'post_name' => $a['slug'],
      'post_title' => $a['title'],
      'post_excerpt' => $a['description'],
      'post_content' => "<!-- wp:html -->\n" . $content . "\n<!-- /wp:html -->",
      'post_date' => $a['date'] . ' 10:00:00',
      'post_date_gmt' => get_gmt_from_date($a['date'] . ' 10:00:00'),
    ];
    if ($id) update_post_meta($id, '_bt_force_slug', 1);
    $id = wp_insert_post(wp_slash($data), true);
    if (is_wp_error($id)) continue;
    update_post_meta($id, '_bt_force_slug', 1);
    update_post_meta($id, '_bt_path', $sort);
    update_post_meta($id, '_bt_section', 'articles');
    update_post_meta($id, '_bt_old_url', $old);
    update_post_meta($id, '_bt_art_topic', $a['topic']);
    update_post_meta($id, '_bt_art_date', $a['date']);
    update_post_meta($id, 'rank_math_title', $a['title'] . ' | بوكس تيك');
    update_post_meta($id, 'rank_math_description', $a['description']);
    if ($aid) set_post_thumbnail($id, $aid);
    if (function_exists('pll_set_post_language')) pll_set_post_language($id, 'ar');
    if (get_post_field('post_name', $id) !== $a['slug']) {
      wp_update_post(['ID' => $id, 'post_name' => $a['slug']]);
    }
    $n++;
  }

  update_option('bt_art_imported', [
    'count' => $n,
    'at' => current_time('mysql'),
    'batch' => '2015-2016',
  ], false);
  flush_rewrite_rules(false);

  if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::success("BT Articles imported $n pages");
  }
}

// Admin notice
add_action('admin_notices', function () {
  $o = get_option('bt_art_imported');
  if (!$o || !current_user_can('manage_options')) return;
  static $shown = false;
  if ($shown) return;
  $shown = true;
  echo '<div class="notice notice-success"><p>BT Articles: imported ' . (int) $o['count'] . ' articles (' . esc_html($o['batch']) . ') at ' . esc_html($o['at']) . '.</p></div>';
});

// Manual re-import via ?bt_art_import=1 for admins
add_action('init', function () {
  if (!is_admin() && isset($_GET['bt_art_import']) && current_user_can('manage_options')) {
    bt_art_import_batch();
    wp_die('BT Articles import done. <a href="' . esc_url(home_url('/ar/articles/')) . '">Open مقالات</a>');
  }
});
