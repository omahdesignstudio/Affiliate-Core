<?php
/**
 * Plugin Name: UBP Affiliate Core (Lead + PID)
 * Description: MVP Affiliate Tools untuk mitra: Link generator, Lead capture, Grup WA, Magic Link, dan Dashboard progress.
 * Version: 0.1.3
 * Author: UBP
 */

if (!defined('ABSPATH')) exit;

/* =========================================================
 * SECTION 0 — KONFIGURASI DASAR
 * ========================================================= */

if (!defined('UBP_WA_GROUP_URL'))    define('UBP_WA_GROUP_URL', 'https://chat.whatsapp.com/XXXXGRUPANDA');
if (!defined('UBP_WA_API_ACTIVE'))   define('UBP_WA_API_ACTIVE', false);

// Namespace Digital
if (!defined('UBP_AFF_PARAM'))       define('UBP_AFF_PARAM', 'aff_digital');
if (!defined('UBP_PID_PARAM'))       define('UBP_PID_PARAM', 'pid_digital');
if (!defined('UBP_COOKIE_AFF'))      define('UBP_COOKIE_AFF', 'ubp_aff_digital');
if (!defined('UBP_COOKIE_PID'))      define('UBP_COOKIE_PID', 'ubp_pid_digital');
if (!defined('UBP_COOKIE_DAYS'))     define('UBP_COOKIE_DAYS', 90);

/* =========================================================
 * SECTION 1 — POLYFILL & HELPERS (ubpac_*)
 * ========================================================= */

if (!function_exists('ubpac_safe_random_bytes')) {
  function ubpac_safe_random_bytes($len = 16) {
    if (function_exists('random_bytes')) return random_bytes($len);
    if (function_exists('openssl_random_pseudo_bytes')) return openssl_random_pseudo_bytes($len);
    $bytes=''; for($i=0;$i<$len;$i++) $bytes.=chr(mt_rand(0,255)); return $bytes;
  }
}

if (!function_exists('ubpac_generate_pid')) {
  function ubpac_generate_pid() {
    $bin = ubpac_safe_random_bytes(16);
    return substr(strtoupper(bin2hex($bin)), 0, 26);
  }
}
if (!function_exists('ubpac_normalize_wa')) {
  function ubpac_normalize_wa($wa) {
    $wa = preg_replace('/\D+/', '', (string)$wa);
    if (strpos($wa, '62')===0) return $wa;
    if (strpos($wa, '0')===0)  return '62'.substr($wa,1);
    return $wa;
  }
}
if (!function_exists('ubpac_set_cookie')) {
  function ubpac_set_cookie($name, $value, $days=UBP_COOKIE_DAYS) {
    setcookie($name, $value, time()+60*60*24*$days, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', is_ssl(), true);
    $_COOKIE[$name] = $value;
  }
}
if (!function_exists('ubpac_send_wa')) {
  function ubpac_send_wa($to, $message) {
    if (!UBP_WA_API_ACTIVE) { @error_log("[UBP_WA_STUB] to=$to msg=".strip_tags($message)); return true; }
    // TODO: Integrasi WA API
    return true;
  }
}

/* =========================================================
 * SECTION 2 — AKTIVASI: BUAT TABEL (Lead & Clicks + Bio)
 * ========================================================= */
if (!function_exists('ubp_affcore_activate')) {
  function ubp_affcore_activate() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $t_prospek = $wpdb->prefix . 'ubp_prospek';
    $sql1 = "CREATE TABLE $t_prospek (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      pid CHAR(26) NOT NULL UNIQUE,
      affiliate_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      nama VARCHAR(120) NULL,
      email VARCHAR(190) NULL,
      wa VARCHAR(32) NULL,
      source VARCHAR(64) NULL,
      utm_campaign VARCHAR(64) NULL,
      utm_content VARCHAR(64) NULL,
      status ENUM('click','lead','group','registered','paid') NOT NULL DEFAULT 'lead',
      ts_first DATETIME NULL,
      ts_last DATETIME NULL,
      PRIMARY KEY (id),
      KEY idx_affiliate (affiliate_id),
      KEY idx_email (email),
      KEY idx_wa (wa),
      KEY idx_status (status),
      KEY idx_ts_last (ts_last)
    ) $charset;";

    $t_clicks = $wpdb->prefix . 'ubp_clicks';
    $sql2 = "CREATE TABLE $t_clicks (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      pid CHAR(26) NOT NULL,
      affiliate_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      url TEXT NULL,
      referrer TEXT NULL,
      utm_source VARCHAR(64) NULL,
      utm_medium VARCHAR(64) NULL,
      utm_campaign VARCHAR(64) NULL,
      ts DATETIME NULL,
      PRIMARY KEY (id),
      KEY idx_pid (pid),
      KEY idx_affiliate (affiliate_id),
      KEY idx_ts (ts)
    ) $charset;";

    // Bio link
    $t_bio_profile = $wpdb->prefix . 'ubp_bio_profile';
    $sql3 = "CREATE TABLE $t_bio_profile (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      slug VARCHAR(64) NOT NULL UNIQUE,
      subdomain VARCHAR(64) NULL UNIQUE,
      display_name VARCHAR(120) NULL,
      headline VARCHAR(180) NULL,
      avatar_url TEXT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      KEY idx_user (user_id)
    ) $charset;";

    $t_bio_links = $wpdb->prefix . 'ubp_bio_links';
    $sql4 = "CREATE TABLE $t_bio_links (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      link_post_id BIGINT UNSIGNED NOT NULL,
      is_enabled TINYINT(1) NOT NULL DEFAULT 0,
      label_override VARCHAR(160) NULL,
      sort_order INT NOT NULL DEFAULT 0,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_user_post (user_id, link_post_id),
      KEY idx_user (user_id),
      KEY idx_enabled (is_enabled)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql1); dbDelta($sql2); dbDelta($sql3); dbDelta($sql4);

    ubp_affcore_register_cpt_tax();
    flush_rewrite_rules(false);
  }
}
register_activation_hook(__FILE__, 'ubp_affcore_activate');
register_deactivation_hook(__FILE__, function(){ flush_rewrite_rules(false); });

/* =========================================================
 * SECTION 3 — ADMIN: CPT Affiliate Links & Taxonomy
 * ========================================================= */
if (!function_exists('ubp_affcore_register_cpt_tax')) {
  function ubp_affcore_register_cpt_tax() {
    register_post_type('ubp_afflink', [
      'label' => 'Affiliate Links',
      'public' => false,
      'show_ui' => true,
      'menu_icon' => 'dashicons-admin-links',
      'supports' => ['title'],
    ]);
    register_taxonomy('ubp_affcat', 'ubp_afflink', [
      'label' => 'Kategori Link',
      'public' => false,
      'show_ui' => true,
      'hierarchical' => true,
    ]);
  }
}
add_action('init', 'ubp_affcore_register_cpt_tax');

// Meta box Target URL
add_action('add_meta_boxes', function() {
  add_meta_box('ubp_afflink_target', 'Target URL (Landing/Form/Checkout)', function($post){
    $url = get_post_meta($post->ID, '_ubp_target_url', true); ?>
    <label>URL tujuan (tanpa aff/pid):</label>
    <input type="url" name="ubp_target_url" value="<?php echo esc_attr($url); ?>" style="width:100%" placeholder="https://domain.com/landing-a">
    <p class="description">Admin isi URL asli. Di dashboard mitra, sistem membungkus otomatis dengan affiliate & PID.</p>
    <?php
  }, 'ubp_afflink', 'normal', 'high');
});
add_action('save_post_ubp_afflink', function($post_id){
  if (isset($_POST['ubp_target_url'])) {
    update_post_meta($post_id, '_ubp_target_url', esc_url_raw($_POST['ubp_target_url']));
  }
});

/* =========================================================
 * SECTION 4 — ROUTER PRETTY LINK: /go/{aff}/{slug}
 * ========================================================= */
add_action('init', function(){
  add_rewrite_rule('^go/([0-9]+)/([^/]+)/?', 'index.php?ubp_go_aff=$matches[1]&ubp_go_slug=$matches[2]', 'top');
});
add_filter('query_vars', function($qv){ $qv[]='ubp_go_aff'; $qv[]='ubp_go_slug'; return $qv; });
add_action('template_redirect', function(){
  $aff = get_query_var('ubp_go_aff');
  $slug = get_query_var('ubp_go_slug');
  if ($aff && $slug) {
    $post = get_page_by_path($slug, OBJECT, 'ubp_afflink');
    if (!$post) wp_die('Link tidak ditemukan.');
    $target = get_post_meta($post->ID, '_ubp_target_url', true);
    if (!$target) wp_die('Target URL belum di-set admin.');

    $pid = isset($_COOKIE[UBP_COOKIE_PID]) ? $_COOKIE[UBP_COOKIE_PID] : ubpac_generate_pid();
    ubpac_set_cookie(UBP_COOKIE_PID, $pid);
    ubpac_set_cookie(UBP_COOKIE_AFF, (int)$aff);

    global $wpdb;
    $wpdb->insert($wpdb->prefix.'ubp_clicks', [
      'pid'=>$pid,'affiliate_id'=>(int)$aff,
      'url'=>esc_url_raw(home_url($_SERVER['REQUEST_URI'] ?? '')),
      'referrer'=>esc_url_raw($_SERVER['HTTP_REFERER'] ?? ''),
      'utm_source'=>sanitize_text_field($_GET['utm_source'] ?? ''),
      'utm_medium'=>sanitize_text_field($_GET['utm_medium'] ?? ''),
      'utm_campaign'=>sanitize_text_field($_GET['utm_campaign'] ?? ''),
      'ts'=>current_time('mysql'),
    ]);

    $sep = (strpos($target,'?')!==false) ? '&' : '?';
    $redir = $target . $sep . UBP_AFF_PARAM.'='.(int)$aff . '&'.UBP_PID_PARAM.'='.rawurlencode($pid);
    wp_redirect($redir); exit;
  }
});

/* =========================================================
 * SECTION 4B — PUBLIC BIO & QR
 * ========================================================= */
add_action('init', function(){ add_rewrite_rule('^bio/([^/]+)/?$', 'index.php?ubp_bio_slug=$matches[1]', 'top'); });
add_filter('query_vars', function($qv){ $qv[]='ubp_bio_slug'; return $qv; });

add_action('init', function(){ add_rewrite_rule('^ubp-bio-qr/?$', 'index.php?ubp_bio_qr=1', 'top'); });
add_filter('query_vars', function($qv){ $qv[]='ubp_bio_qr'; $qv[]='slug'; return $qv; });

add_action('template_redirect', function(){
  $slug = get_query_var('ubp_bio_slug'); if (!$slug) return;
  global $wpdb;
  $tP = $wpdb->prefix.'ubp_bio_profile';
  $tL = $wpdb->prefix.'ubp_bio_links';
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tP WHERE slug=%s AND is_active=1", $slug));
  if (!$row){ status_header(404); wp_die('Bio tidak ditemukan.'); }
  $links = $wpdb->get_results($wpdb->prepare("
    SELECT bl.*, p.post_title, p.post_name AS aff_slug
    FROM $tL bl
    JOIN {$wpdb->posts} p ON p.ID=bl.link_post_id AND p.post_type='ubp_afflink' AND p.post_status='publish'
    WHERE bl.user_id=%d AND bl.is_enabled=1
    ORDER BY bl.sort_order ASC, bl.updated_at DESC, p.post_title ASC
  ", (int)$row->user_id));
  $aff_id = (int)$row->user_id;
  $qr_url = add_query_arg(['slug'=>$slug], home_url('/ubp-bio-qr'));
  status_header(200); header('Content-Type: text/html; charset=utf-8'); ?>
  <!doctype html>
  <html><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo esc_html($row->display_name ?: $slug); ?> — Bio Link</title>
    <style>
      body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial;margin:0;background:#f7f7fb;color:#0f172a}
      .wrap{max-width:560px;margin:24px auto;padding:16px}
      .head{display:flex;gap:12px;align-items:center;margin-bottom:14px}
      .ava{width:64px;height:64px;border-radius:50%;border:1px solid #e5e7eb;object-fit:cover;background:#fff}
      .name{font-weight:800;font-size:18px;line-height:1.1}
      .sub{color:#6b7280;font-size:13px}
      .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:12px;margin:8px 0;display:flex;justify-content:space-between;align-items:center}
      .btn{display:block;text-align:center;background:#3b82f6;color:#fff;padding:12px;border-radius:12px;text-decoration:none;font-weight:700}
      .qr{margin:10px 0 2px; text-align:center} img{max-width:100%}
    </style>
  </head><body>
    <div class="wrap">
      <div class="head">
        <img class="ava" src="<?php echo esc_url($row->avatar_url ?: get_avatar_url($row->user_id,['size'=>128]) ); ?>" alt="Avatar">
        <div>
          <div class="name"><?php echo esc_html($row->display_name ?: '@'.$slug); ?></div>
          <?php if ($row->headline): ?><div class="sub"><?php echo esc_html($row->headline); ?></div><?php endif; ?>
        </div>
      </div>
      <?php if (!empty($links)): foreach($links as $l):
        $title = $l->label_override ?: $l->post_title;
        $url   = home_url("/go/$aff_id/{$l->aff_slug}"); ?>
        <div class="card">
          <div style="min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
            <strong><?php echo esc_html($title); ?></strong>
          </div>
          <a class="btn" href="<?php echo esc_url($url); ?>">Buka</a>
        </div>
      <?php endforeach; else: ?>
        <div class="card"><em>Tidak ada link yang diaktifkan.</em></div>
      <?php endif; ?>
      <div class="qr">
        <img alt="QR" src="<?php echo esc_url($qr_url); ?>">
        <div class="sub"><a href="<?php echo esc_url($qr_url); ?>" download="qr-<?php echo esc_attr($slug); ?>.png">Download QR</a></div>
      </div>
    </div>
  </body></html>
  <?php exit;
});

add_action('template_redirect', function(){
  if (!get_query_var('ubp_bio_qr')) return;
  $slug = sanitize_title(get_query_var('slug')); if (!$slug){ status_header(400); echo 'Bad Request'; exit; }
  $bio_url = home_url('/bio/'. $slug);
  $qr = 'https://api.qrserver.com/v1/create-qr-code/?size=512x512&data=' . rawurlencode($bio_url);
  wp_redirect($qr); exit;
});

/* =========================================================
 * SECTION 5 — SHORTCODE FORM LEAD
 * ========================================================= */
add_shortcode('ubp_lead_form', function($atts){
  $g_show_email = get_option('ubp_form_show_email','1');
  $g_show_wa    = get_option('ubp_form_show_wa','1');
  $g_btn_label  = get_option('ubp_form_button','Daftar');

  $default_fields = ($g_show_email==='1' && $g_show_wa==='1') ? 'name,email,wa' : (($g_show_email==='1') ? 'name,email' : 'name,wa');

  $a = shortcode_atts([
    'after'    => 'group',
    'redirect' => '',
    'group'    => UBP_WA_GROUP_URL,
    'button'   => $g_btn_label,
    'send_wa'  => '1',
    'fields'   => $default_fields,
  ], $atts);

  $fields     = array_map('trim', explode(',', strtolower($a['fields'])));
  $show_email = in_array('email', $fields, true);
  $show_wa    = in_array('wa', $fields, true);

  $errors = [];
  $success_pid = '';

  $aff = isset($_GET[UBP_AFF_PARAM]) ? (int)$_GET[UBP_AFF_PARAM] : (int)($_COOKIE[UBP_COOKIE_AFF] ?? 0);
  $pid = isset($_GET[UBP_PID_PARAM]) ? sanitize_text_field($_GET[UBP_PID_PARAM]) : ($_COOKIE[UBP_COOKIE_PID] ?? ubpac_generate_pid());

  if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ubp_lf_nonce']) && wp_verify_nonce($_POST['ubp_lf_nonce'], 'ubp_lf')) {
    $nama   = sanitize_text_field($_POST['nama']  ?? '');
    $email  = sanitize_email($_POST['email'] ?? '');
    $wa_in  = $_POST['wa'] ?? '';
    $wa     = $wa_in !== '' ? ubpac_normalize_wa($wa_in) : '';
    $aff    = (int)($_POST[UBP_AFF_PARAM] ?? $aff);
    $pid    = $_POST[UBP_PID_PARAM] ?? $pid;

    if (!$nama) $errors[] = 'Nama wajib diisi.';
    if ($show_email && $show_wa) {
      if ($email==='' && $wa==='') $errors[] = 'Isi minimal Email atau Nomor WA.';
    } elseif ($show_email && !$show_wa) {
      if ($email==='') $errors[] = 'Email wajib diisi.';
    } elseif ($show_wa && !$show_email) {
      if ($wa==='') $errors[] = 'Nomor WA wajib diisi.';
    } else {
      if ($email==='' && $wa==='') $errors[] = 'Isi minimal Email atau Nomor WA.';
    }

    if (empty($errors)) {
      global $wpdb; $t = $wpdb->prefix.'ubp_prospek';
      $now = current_time('mysql');
      $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE pid=%s", $pid));
      if ($row) {
        $wpdb->update($t, [
          'affiliate_id'=>$aff, 'nama'=>$nama, 'email'=>$email, 'wa'=>$wa,
          'status'=>'lead', 'ts_last'=>$now
        ], ['pid'=>$pid]);
      } else {
        $wpdb->insert($t, [
          'pid'=>$pid, 'affiliate_id'=>$aff, 'nama'=>$nama, 'email'=>$email, 'wa'=>$wa,
          'status'=>'lead', 'ts_first'=>$now, 'ts_last'=>$now
        ]);
      }

      ubpac_set_cookie(UBP_COOKIE_PID, $pid);
      ubpac_set_cookie(UBP_COOKIE_AFF, $aff);

      if ($a['send_wa'] === '1' && $wa) {
        $hub = add_query_arg([UBP_PID_PARAM=>$pid], home_url('/continue'));
        $msg = "Halo $nama, terima kasih sudah mendaftar.\n".
               "Ini tautan pribadi Anda: $hub\n".
               "Anda bisa lanjut daftar akun / cek materi / beli produk kapan saja.";
        ubpac_send_wa($wa, $msg);
      }

      if ($a['after'] === 'group' && !empty($a['group'])) {
        wp_redirect($a['group']); exit;
      } elseif ($a['after'] === 'url' && !empty($a['redirect'])) {
        wp_redirect(esc_url_raw($a['redirect'])); exit;
      } elseif ($a['after'] === 'hub') {
        $hub = add_query_arg([UBP_PID_PARAM=>$pid], home_url('/continue'));
        wp_redirect($hub); exit;
      } else {
        $success_pid = $pid;
      }
    }
  }

  $aff = (int)$aff;
  $pid = $pid ?: ubpac_generate_pid();

  ob_start(); ?>
  <style>
    .ubp-form-app { --pad:16px; --bg:#f7f7fb; --card:#ffffff; --line:#e5e7eb; --ink:#0f172a; --muted:#6b7280; --brand:#3b82f6; --radius:16px; }
    .ubp-form-wrap { background:var(--bg); padding:var(--pad); border-radius:12px; }
    .ubp-card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); padding:16px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .ubp-title { font-size:18px; font-weight:700; margin:0 0 8px; }
    .ubp-desc  { color:var(--muted); font-size:13px; margin-bottom:14px; }
    .ubp-field { margin-bottom:12px; }
    .ubp-label { display:block; font-weight:600; margin-bottom:6px; }
    .ubp-input { width:100%; border:1px solid var(--line); padding:12px 14px; border-radius:12px; font-size:15px; outline:none; }
    .ubp-input:focus { border-color:var(--brand); box-shadow:0 0 0 3px rgba(59,130,246,.15); }
    .ubp-btn-primary { width:100%; background:var(--brand); color:#fff; border:0; padding:12px 16px; border-radius:12px; font-weight:700; font-size:16px; cursor:pointer; }
    .ubp-errors { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; padding:10px 12px; border-radius:10px; margin-bottom:12px; font-size:13px; }
    .ubp-success { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; padding:12px; border-radius:12px; }
    .ubp-row { display:flex; gap:8px; flex-wrap:wrap; }
    .ubp-badge { background:#f3f4f6; color:#111827; border-radius:999px; padding:4px 10px; font-size:12px; font-weight:700; }
    .ubp-mini { color:var(--muted); font-size:12px; margin-top:8px; }
    .ubp-affcard { margin-top:14px; display:flex; gap:12px; align-items:center; background:#f8fafc; border:1px solid #e5e7eb; border-radius:14px; padding:12px; }
    .ubp-affcard .ava { width:52px; height:52px; border-radius:50%; overflow:hidden; flex:0 0 52px; border:1px solid #e5e7eb; }
    .ubp-affcard .ava img { width:100%; height:100%; object-fit:cover; display:block; }
    .ubp-affcard .label { font-size:12px; color:#64748b; margin-bottom:2px; }
    .ubp-affcard .name  { font-size:15px; font-weight:800; color:#0f172a; line-height:1.15; }
    .ubp-affcard .sub   { font-size:12px; color:#6b7280; }
  </style>

  <div class="ubp-form-app">
    <div class="ubp-form-wrap">
      <?php if ($success_pid): ?>
        <div class="ubp-card ubp-success">
          <div class="ubp-title" style="margin-bottom:6px;">Berhasil! 🎉</div>
          <div>Data Anda sudah kami terima. Kami juga mengirimkan MagicLink ke WhatsApp Anda (jika diaktifkan).</div>
          <div class="ubp-mini">PID: <code><?php echo esc_html($success_pid); ?></code></div>
          <div class="ubp-row" style="margin-top:10px;">
            <a class="ubp-badge" href="<?php echo esc_url( add_query_arg([UBP_PID_PARAM=>$success_pid], home_url('/continue')) ); ?>">Buka MagicLink</a>
            <?php if (!empty($a['group'])): ?>
              <a class="ubp-badge" href="<?php echo esc_url($a['group']); ?>" target="_blank" rel="noopener">Masuk Grup</a>
            <?php endif; ?>
          </div>
        </div>
      <?php else: ?>
        <?php if (!empty($errors)): ?>
          <div class="ubp-errors"><?php foreach ($errors as $e): ?><div>• <?php echo esc_html($e); ?></div><?php endforeach; ?></div>
        <?php endif; ?>

        <div class="ubp-card">
          <div class="ubp-title">Daftar sebagai Prospek</div>
          <div class="ubp-desc">Masukkan data Anda. Kami akan mengirimkan tautan pribadi (MagicLink) untuk langkah selanjutnya.</div>

          <form method="post" class="ubp-lead-form">
            <?php wp_nonce_field('ubp_lf','ubp_lf_nonce'); ?>
            <input type="hidden" name="<?php echo esc_attr(UBP_AFF_PARAM); ?>" value="<?php echo esc_attr($aff); ?>">
            <input type="hidden" name="<?php echo esc_attr(UBP_PID_PARAM); ?>" value="<?php echo esc_attr($pid); ?>">

            <div class="ubp-field">
              <label class="ubp-label">Nama <span style="color:#ef4444">*</span></label>
              <input class="ubp-input" type="text" name="nama" required placeholder="Nama lengkap">
            </div>

            <?php if ($show_email): ?>
            <div class="ubp-field">
              <label class="ubp-label">Email<?php echo $show_wa ? '' : ' <span style="color:#ef4444">*</span>'; ?></label>
              <input class="ubp-input" type="email" name="email" placeholder="Email aktif">
            </div>
            <?php endif; ?>

            <?php if ($show_wa): ?>
            <div class="ubp-field">
              <label class="ubp-label">Nomor WA<?php echo $show_email ? '' : ' <span style="color:#ef4444">*</span>'; ?></label>
              <input class="ubp-input" type="tel" name="wa" placeholder="08xxxxxxxxxx">
            </div>
            <?php endif; ?>

            <button type="submit" class="ubp-btn-primary"><?php echo esc_html($a['button']); ?></button>

            <div class="ubp-mini">Dengan menekan tombol, Anda menyetujui kami menghubungi via WhatsApp untuk mengirim tautan dan info lanjutan.</div>

            <?php if ($aff) :
              $aff_user = get_userdata($aff);
              if ($aff_user) :
                $ava_url = get_avatar_url($aff_user->ID, ['size'=>96]) ?: 'https://www.gravatar.com/avatar/?d=mp&s=96';
                $aff_name = $aff_user->display_name ?: $aff_user->user_login; ?>
              <div class="ubp-affcard">
                <div class="ava"><img src="<?php echo esc_url($ava_url); ?>" alt="Avatar Affiliate"></div>
                <div>
                  <div class="label">Yang Memberikan Informasi Kepada Anda</div>
                  <div class="name"><?php echo esc_html($aff_name); ?></div>
                  <div class="sub">Siap membantu Anda hingga tuntas</div>
                </div>
              </div>
            <?php endif; endif; ?>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php
  return ob_get_clean();
});

/* =========================================================
 * SECTION 6 — HALAMAN HUB "/continue"
 * ========================================================= */
add_action('init', function(){
  add_rewrite_rule('^continue/?$', 'index.php?ubp_continue=1', 'top');
});
add_filter('query_vars', function($qv){ $qv[]='ubp_continue'; return $qv; });
add_action('template_redirect', function(){
  if (get_query_var('ubp_continue')) {
    $pid = sanitize_text_field($_GET[UBP_PID_PARAM] ?? ''); if (!$pid) wp_die('PID tidak ditemukan.');
    $register = add_query_arg([UBP_PID_PARAM=>$pid], wp_registration_url());
    $checkout = home_url('/checkout-digital?'.UBP_PID_PARAM.'='.rawurlencode($pid));
    $materi   = home_url('/materi?'.UBP_PID_PARAM.'='.rawurlencode($pid));
    status_header(200); header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head><meta charset='utf-8'><title>Lanjutkan</title></head>
    <body style='font-family:sans-serif;max-width:640px;margin:40px auto;'>
      <h2>Lanjutkan Proses</h2>
      <ul>
        <li><a href='".esc_url($register)."'>Daftar Akun (gratis)</a></li>
        <li><a href='".esc_url($checkout)."'>Checkout Produk Digital</a></li>
        <li><a href='".esc_url($materi)."'>Lihat Materi</a></li>
      </ul>
      <p><small>PID: ".esc_html($pid)."</small></p>
    </body></html>"; exit;
  }
});

/* =========================================================
 * SECTION 7 — BINDING SPONSOR SAAT REGISTER
 * ========================================================= */
add_action('user_register', function($user_id){
  $pid = sanitize_text_field($_GET[UBP_PID_PARAM] ?? ($_COOKIE[UBP_COOKIE_PID] ?? ''));
  $aff = (int)($_COOKIE[UBP_COOKIE_AFF] ?? 0);
  global $wpdb; $t = $wpdb->prefix.'ubp_prospek';
  if ($pid) { $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE pid=%s", $pid)); if ($row) { $aff = (int)$row->affiliate_id; } }
  if ($aff) {
    update_user_meta($user_id, 'affiliate_id_digital', $aff);
    if ($pid) { $wpdb->update($t, ['status'=>'registered','ts_last'=>current_time('mysql')], ['pid'=>$pid]); }
  }
}, 10, 1);

/* =========================================================
 * SECTION 8 — DASHBOARD: AFFILIATE TOOLS (+ Ads Generator)
 * Shortcode: [ubp_affiliate_tools]
 * ========================================================= */
add_shortcode('ubp_affiliate_tools', function($atts){
  if (!is_user_logged_in()) { auth_redirect(); return ''; }

  $user    = wp_get_current_user();
  $aff_id  = (int)$user->ID;
  $site    = home_url();
  $intake  = trailingslashit($site) . 'ubp-intake';
  $affcard = trailingslashit($site) . 'ubp-affcard';

  // Data link by kategori
  $links = get_posts(['post_type'=>'ubp_afflink','numberposts'=>-1,'post_status'=>'publish']);
  $bycat = [];
  foreach ($links as $p) {
    $cats    = wp_get_post_terms($p->ID, 'ubp_affcat', ['fields'=>'names']);
    $target  = get_post_meta($p->ID, '_ubp_target_url', true);
    $slug    = $p->post_name;
    $aff_url = home_url("/go/$aff_id/$slug");
    $bycat[ $cats ? implode(', ',$cats) : 'Umum' ][] = ['title'=>$p->post_title,'target'=>$target,'url'=>$aff_url];
  }

  global $wpdb;
  $tP = $wpdb->prefix.'ubp_prospek';
  $tC = $wpdb->prefix.'ubp_clicks';
  $q  = function($status) use($wpdb,$tP,$aff_id){ return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tP WHERE affiliate_id=%d AND status=%s", $aff_id, $status)); };
  $clicks = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tC WHERE affiliate_id=%d", $aff_id));
  $lead   = $q('lead'); $group=$q('group'); $reg=$q('registered'); $paid=$q('paid');
  $total  = max(1, $clicks + $lead + $group + $reg + $paid);
  $p_click= round(($clicks/$total)*100); $p_lead= round(($lead/$total)*100);
  $p_group= round(($group/$total)*100);  $p_reg = round(($reg/$total)*100);
  $p_paid = round(($paid/$total)*100);

  // Filter + pagination prospek
  $allowed_status = ['lead','group','registered','paid'];
  $f = isset($_GET['f']) ? strtolower(sanitize_text_field($_GET['f'])) : 'all';
  $f = in_array($f, $allowed_status, true) ? $f : 'all';
  $per_page = 10;
  $page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
  $offset = ($page - 1) * $per_page;
  $where = "affiliate_id=%d"; $args=[$aff_id];
  if ($f !== 'all') { $where.=" AND status=%s"; $args[]=$f; }
  $total_rows = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tP WHERE $where", $args));
  $args_page = array_merge($args, [$per_page, $offset]);
  $prospek = $wpdb->get_results($wpdb->prepare("
    SELECT pid, nama, email, wa, status, COALESCE(ts_last, ts_first) AS tsu
    FROM $tP
    WHERE $where
    ORDER BY tsu DESC
    LIMIT %d OFFSET %d
  ", $args_page));
  $total_pages = max(1, (int)ceil($total_rows / $per_page));

  $mask_wa = function($wa){
    $wa = preg_replace('/\D+/', '', (string)$wa);
    if (strlen($wa) <= 4) return $wa;
    return str_repeat('•', max(0, strlen($wa)-4)) . substr($wa, -4);
  };
  $badge = function($st){
    $colors = ['click'=>'#9CA3AF','lead'=>'#3B82F6','group'=>'#F59E0B','registered'=>'#10B981','paid'=>'#14B8A6'];
    $label = ucfirst($st); $bg = $colors[$st] ?? '#6B7280';
    return "<span class='ubp-badge' style='background:$bg'>$label</span>";
  };

  // Defaults generator
  $pix_id   = get_user_meta($aff_id, 'ubp_pixel_id', true) ?: '';
  $pix_evt  = get_user_meta($aff_id, 'ubp_pixel_event', true) ?: 'Lead';
  $pix_val  = get_user_meta($aff_id, 'ubp_pixel_value', true) ?: '';
  $pix_cur  = get_user_meta($aff_id, 'ubp_pixel_currency', true) ?: 'IDR';
  $pix_dly  = get_user_meta($aff_id, 'ubp_pixel_delay', true) ?: '300';
  $g_show_email = get_option('ubp_form_show_email','1');
  $g_show_wa    = get_option('ubp_form_show_wa','1');
  $g_btn_label  = get_option('ubp_form_button','Daftar');
  $after_default    = get_option('ubp_aff_after', 'hub');
  $group_default    = get_option('ubp_aff_group', UBP_WA_GROUP_URL);
  $redirect_default = get_option('ubp_aff_redirect', home_url('/terimakasih'));
  $hub_default      = get_option('ubp_aff_hub', home_url('/continue'));

  ob_start(); ?>
  <style>
    .ubp-app { --pad:16px; --radius:16px; --muted:#6b7280; --card:#fff; --bg:#f7f7fb; --ink:#0f172a; --line:#e5e7eb; --brand:#3b82f6; }
    .ubp-app { font-family: system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial; color:var(--ink); background:var(--bg); padding:var(--pad); border-radius:12px; }
    .ubp-section { margin-bottom:22px; }
    .ubp-card { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:16px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .ubp-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .ubp-wrap { overflow-wrap:anywhere; word-break:break-word; white-space:normal; }
    .ubp-nowrap { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .ubp-h3 { font-size:18px; font-weight:700; margin:4px 0 12px; }
    .ubp-h4 { font-size:15px; font-weight:600; margin:16px 0 10px; color:#111827; }
    .ubp-row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .ubp-btn { appearance:none; border:0; border-radius:12px; padding:10px 14px; font-weight:600; cursor:pointer; }
    .ubp-btn.primary { background:var(--brand); color:#fff; }
    .ubp-btn.ghost { background:#eef2ff; color:#4338ca; }
    .ubp-badge { color:#fff; border-radius:999px; padding:3px 10px; font-size:11px; font-weight:700; }
    .ubp-stack { display:grid; gap:12px; }
    @media(min-width:720px){ .ubp-stack.cols-2{ grid-template-columns:repeat(2,1fr);} }
    .ubp-tabs { display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap; }
    .ubp-tab { padding:8px 12px; border-radius:999px; border:1px solid var(--line); background:#f3f4f6; cursor:pointer; font-weight:700; font-size:13px; }
    .ubp-tab.active { background:#e0e7ff; color:#1e40af; border-color:#c7d2fe; }
    .ubp-pane { display:none; } .ubp-pane.active { display:block; }
    .ubp-code { width:100%; height:220px; font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:12px; background:#0b1220; color:#d1e0ff; border-radius:10px; padding:10px; border:1px solid #0f172a; }
    .field { width:100%; padding:10px; border:1px solid #e5e7eb; border-radius:10px; }
    label.small{font-size:12px;color:#6b7280;display:block;margin-top:2px}
    .switch { position:relative; display:inline-block; width:44px; height:24px; vertical-align:middle; }
    .switch input { display:none; }
    .slider { position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background:#d1d5db; transition:.2s; border-radius:999px; }
    .slider:before { position:absolute; content:""; height:18px; width:18px; left:3px; top:3px; background:#fff; transition:.2s; border-radius:50%; box-shadow:0 1px 2px rgba(0,0,0,.2); }
    .switch input:checked + .slider { background:#34d399; }
    .switch input:checked + .slider:before { transform:translateX(20px); }
  </style>

  <div class="ubp-app" id="ubpApp">
    <div class="ubp-row" style="align-items:center; margin-bottom:10px; gap:10px;">
      <button class="ubp-btn ghost" type="button" onclick="history.back()">← Back</button>
      <div style="font-weight:800; font-size:18px;">Affiliate Tools</div>
    </div>

    <div class="ubp-tabs">
      <div class="ubp-tab active" data-tab="tools">Tools</div>
      <div class="ubp-tab" data-tab="adsgen">Ads Generator</div>
      <div class="ubp-tab" data-tab="personal">Personal Link</div>
    </div>

    <!-- ===== TAB: TOOLS ===== -->
    <div class="ubp-pane active" id="pane-tools">
      <div class="ubp-section">
        <div class="ubp-h3">Affiliate Tools</div>
        <?php foreach ($bycat as $cat=>$items): ?>
          <div class="ubp-h4"><?php echo esc_html($cat); ?></div>
          <div class="ubp-stack cols-2">
          <?php foreach ($items as $row): ?>
            <div class="ubp-card">
              <div style="min-width:0;">
                <div style="font-weight:700;font-size:15px;line-height:1.2;"><?php echo esc_html($row['title']); ?></div>
                <div class="meta ubp-mono ubp-wrap" style="font-size:12px;color:#6b7280;">Link Affiliate: <span><?php echo esc_html($row['url']); ?></span></div>
              </div>
              <div class="ubp-row" style="margin-top:10px;">
                <button class="ubp-btn primary" data-copy="<?php echo esc_attr($row['url']); ?>">Copy Link</button>
                <a class="ubp-btn ghost" href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener">Preview</a>
              </div>
            </div>
          <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>

            <div class="ubp-section">
        <div class="ubp-card">
          <div class="ubp-h3" style="margin:0 0 8px;">Progress Funnel</div>
          <div class="ubp-row">
            <div class="ubp-badge" style="background:#9CA3AF">Clicks: <b><?php echo (int)$clicks; ?></b></div>
            <div class="ubp-badge" style="background:#3B82F6">Leads: <b><?php echo (int)$lead; ?></b></div>
            <div class="ubp-badge" style="background:#F59E0B">Group: <b><?php echo (int)$group; ?></b></div>
            <div class="ubp-badge" style="background:#10B981">Registered: <b><?php echo (int)$reg; ?></b></div>
            <div class="ubp-badge" style="background:#14B8A6">Paid: <b><?php echo (int)$paid; ?></b></div>
          </div>
          <div style="height:10px;background:#e5e7eb;border-radius:999px;overflow:hidden;margin-top:10px;display:flex">
            <div style="width:<?php echo $p_click; ?>%;background:#d1d5db"></div>
            <div style="width:<?php echo $p_lead;  ?>%;background:#cfe3ff"></div>
            <div style="width:<?php echo $p_group; ?>%;background:#ffe6b3"></div>
            <div style="width:<?php echo $p_reg;   ?>%;background:#bbf7d0"></div>
            <div style="width:<?php echo $p_paid;  ?>%;background:#99f6e4"></div>
          </div>
        </div>
      </div>

      <!-- ===== Prospek: Filter + List (AJAX-ready, aman di Elementor) ===== -->
      <div class="ubp-section">
        <div class="ubp-h3">Daftar Prospek</div>

        <div class="ubp-row" id="ubpProspekFilter" style="margin:6px 0 12px; gap:8px; flex-wrap:wrap;">
          <?php
            $caps = [
              'all'        => 'All',
              'lead'       => 'Leads',
              'group'      => 'Group',
              'registered' => 'Registered',
              'paid'       => 'Paid',
            ];
            foreach ($caps as $key=>$lbl):
              $active = ($key === $f);
          ?>
            <a href="#"
               class="ubp-badge js-prospek-filter"
               data-filter="<?php echo esc_attr($key); ?>"
               style="background:<?php echo $active ? '#e0e7ff' : '#f3f4f6'; ?>; color:<?php echo $active ? '#1e40af' : '#111827'; ?>; border:1px solid #e5e7eb;">
              <?php echo esc_html($lbl); ?>
            </a>
          <?php endforeach; ?>
        </div>

        <!-- WRAPPER: List (akan di-swap via AJAX) -->
        <div id="ubpProspekList" class="ubp-stack">
          <?php if (!empty($prospek)): foreach ($prospek as $p):
            $wa_mask = $mask_wa($p->wa);
            $hub     = add_query_arg([ UBP_PID_PARAM => $p->pid ], home_url('/continue'));
            $sapaan  = rawurlencode("Assalamualaikum, ini tautan pribadi Anda untuk melanjutkan: $hub");
            $wa_url  = $p->wa ? "https://wa.me/{$p->wa}?text={$sapaan}" : '#';
            $joined  = add_query_arg([ UBP_PID_PARAM => $p->pid ], home_url('/joined'));
          ?>
            <div class="ubp-card ubp-prospect" data-pid="<?php echo esc_attr($p->pid); ?>">
              <div class="ubp-row" style="justify-content:space-between">
                <div>
                  <div style="font-weight:700;font-size:15px"><?php echo esc_html($p->nama ?: '(Tanpa Nama)'); ?></div>
                  <div class="meta">
                    <?php echo $badge($p->status); ?>
                    <span style="margin-left:8px;color:#6b7280;"><?php echo esc_html( $p->tsu ? date_i18n('d M Y H:i', strtotime($p->tsu)) : '-' ); ?></span>
                  </div>
                </div>
                <button class="ubp-btn ghost toggle" type="button">Detail</button>
              </div>
              <div class="detail" style="display:none;padding-top:8px;border-top:1px dashed #e5e7eb;font-size:14px">
                <div style="display:grid;grid-template-columns:1fr;gap:6px;margin-bottom:8px;">
                  <div><strong>Email:</strong> <?php echo esc_html($p->email ?: '-'); ?></div>
                  <div><strong>WA:</strong> <?php echo esc_html($wa_mask ?: '-'); ?></div>
                  <div><strong>PID:</strong> <code><?php echo esc_html($p->pid); ?></code></div>
                </div>
                <div class="ubp-row">
                  <button class="ubp-btn primary" data-copy="<?php echo esc_attr($hub); ?>">Copy MagicLink</button>
                  <?php if ($p->wa): ?><a class="ubp-btn ghost" href="<?php echo esc_url($wa_url); ?>" target="_blank" rel="noopener">Chat WA</a><?php endif; ?>
                  <a class="ubp-btn ghost" href="<?php echo esc_url($joined); ?>">Tandai Gabung</a>
                </div>
              </div>
            </div>
          <?php endforeach; else: ?>
            <div class="ubp-card">Belum ada prospek untuk ditampilkan.</div>
          <?php endif; ?>
        </div>

        <!-- WRAPPER: Pager (akan di-swap via AJAX) -->
        <?php if ($total_pages > 1): ?>
          <div id="ubpProspekPager" class="ubp-row" style="justify-content:center;margin-top:8px">
            <?php
              $prev = max(1, $page-1);
              $next = min($total_pages, $page+1);
            ?>
            <a class="ubp-btn ghost ubp-pg" href="#" data-f="<?php echo esc_attr($f); ?>" data-p="<?php echo esc_attr($prev); ?>">‹ Prev</a>
            <div style="padding:6px 10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;font-size:12px;">
              Page <?php echo (int)$page; ?> / <?php echo (int)$total_pages; ?>
            </div>
            <a class="ubp-btn ghost ubp-pg" href="#" data-f="<?php echo esc_attr($f); ?>" data-p="<?php echo esc_attr($next); ?>">Next ›</a>
          </div>
        <?php else: ?>
          <div id="ubpProspekPager"></div>
        <?php endif; ?>
      </div> <!-- /Prospek Section -->
    </div> <!-- /pane-tools -->

    <!-- ===== TAB: ADS GENERATOR (Form + Pixel; 1 bundle snippet) ===== -->
    <div class="ubp-pane" id="pane-adsgen">
      <div class="ubp-card">
        <div class="ubp-h3">Ads Generator — Form & Pixel Bundle</div>
        <p style="color:#6b7280;margin-top:0">
          Isi pengaturan di bawah, lalu klik <b>Generate</b>. Salin 1 kode di bawah dan tempel di landing page Anda (domain Anda).
          Kode sudah berisi <i>Form</i> + <i>Affiliate Card</i> + <i>Pixel Event</i> dan kirim data ke pusat (<code><?php echo esc_html($intake); ?></code>).
        </p>

        <!-- Baris 1: Pixel -->
        <div class="ubp-row">
          <div style="flex:1;min-width:260px">
            <label>Meta Pixel ID</label>
            <input id="gen_pix_id" class="field" type="text" value="<?php echo esc_attr($pix_id); ?>">
            <label class="small">Contoh: 123456789012345</label>
          </div>
          <div>
            <label>Event</label>
            <select id="gen_pix_evt" class="field" style="width:220px">
              <?php foreach(['Lead','CompleteRegistration','ViewContent','AddToCart','Purchase'] as $ev): ?>
                <option value="<?php echo esc_attr($ev); ?>" <?php selected($pix_evt,$ev); ?>><?php echo esc_html($ev); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Delay (ms)</label>
            <input id="gen_pix_dly" class="field" type="number" min="0" value="<?php echo esc_attr($pix_dly); ?>" style="width:140px">
          </div>
        </div>

        <!-- Baris 2: Value/Currency -->
        <div class="ubp-row" style="margin-top:10px">
          <div>
            <label>Value (opsional)</label>
            <input id="gen_pix_val" class="field" type="text" value="<?php echo esc_attr($pix_val); ?>" placeholder="0 atau 149000" style="width:180px">
          </div>
          <div>
            <label>Currency</label>
            <input id="gen_pix_cur" class="field" type="text" value="<?php echo esc_attr($pix_cur); ?>" style="width:120px">
          </div>
        </div>

        <!-- Baris 3: Form fields -->
        <div class="ubp-row" style="margin-top:12px">
          <div>
            <label>Form Fields</label><br>
            <label><input type="checkbox" id="gen_show_email" <?php checked($g_show_email,'1'); ?>> Tampilkan Email</label><br>
            <label><input type="checkbox" id="gen_show_wa" <?php checked($g_show_wa,'1'); ?>> Tampilkan Nomor WA</label>
          </div>
          <div style="flex:1;min-width:260px">
            <label>Label Tombol (CTA)</label>
            <input id="gen_btn_label" class="field" type="text" value="<?php echo esc_attr($g_btn_label); ?>">
          </div>
        </div>

        <!-- Baris 4: Redirect -->
        <div class="ubp-row" style="margin-top:12px">
          <div>
            <label>Redirect Setelah Submit</label>
            <select id="gen_after" class="field" style="width:220px">
              <?php foreach(['group'=>'Grup WhatsApp','url'=>'Custom URL','hub'=>'Halaman Hub (/continue)','none'=>'Tidak Redirect'] as $k=>$lbl): ?>
                <option value="<?php echo esc_attr($k); ?>" <?php selected($after_default,$k); ?>><?php echo esc_html($lbl); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="flex:1;min-width:260px">
            <label>Link Grup (jika pilih Grup)</label>
            <input id="gen_group" class="field" type="url" value="<?php echo esc_attr($group_default); ?>">
          </div>
        </div>
        <div class="ubp-row" style="margin-top:8px">
          <div style="flex:1;min-width:260px">
            <label>Redirect URL (jika pilih URL)</label>
            <input id="gen_redirect" class="field" type="url" value="<?php echo esc_attr($redirect_default); ?>">
          </div>
          <div style="flex:1;min-width:260px">
            <label>Hub URL (jika pilih Hub)</label>
            <input id="gen_hub" class="field" type="url" value="<?php echo esc_attr($hub_default); ?>">
          </div>
        </div>

        <div class="ubp-row" style="margin-top:12px">
          <button class="ubp-btn primary" id="btnGen">Generate</button>
          <button class="ubp-btn ghost" id="btnSaveDefaults">Simpan Default</button>
        </div>
      </div>

      <!-- OUTPUT: BUNDLE SNIPPET -->
      <div class="ubp-section">
        <div class="ubp-card">
          <div class="ubp-h4">Form Bundle Snippet (Form + Affiliate Card + Pixel Event)</div>
          <textarea id="code_bundle" class="ubp-code" readonly></textarea>
          <div class="ubp-row" style="margin-top:8px">
            <button class="ubp-btn primary" data-copy-target="#code_bundle">Copy</button>
          </div>
          <p style="color:#6b7280;margin:10px 0 0">Tempel kode ini di landing page Anda. Sudah include tracking Pixel event pilihan Anda, dan data submit akan dikirim ke pusat.</p>
        </div>
      </div>

      <!-- OPSIONAL: HEAD & EVENT SNIPPETS TERPISAH -->
      <div class="ubp-section">
        <div class="ubp-card">
          <div class="ubp-h4">Head Snippet (opsional — base Meta Pixel)</div>
          <textarea id="code_head" class="ubp-code" readonly></textarea>
          <div class="ubp-row" style="margin-top:8px">
            <button class="ubp-btn primary" data-copy-target="#code_head">Copy</button>
          </div>
          <p style="color:#6b7280;margin:10px 0 0">Pakai ini kalau landing page Anda belum memasang base Meta Pixel di &lt;head&gt;.</p>
        </div>
      </div>
      <div class="ubp-section">
        <div class="ubp-card">
          <div class="ubp-h4">Event Snippet (opsional — hanya event-nya saja)</div>
          <textarea id="code_evt" class="ubp-code" readonly></textarea>
          <div class="ubp-row" style="margin-top:8px">
            <button class="ubp-btn primary" data-copy-target="#code_evt">Copy</button>
          </div>
          <p style="color:#6b7280;margin:10px 0 0">Untuk advanced user. Kalau bundle di atas sudah dipakai, Anda tidak perlu menaruh snippet ini lagi.</p>
        </div>
      </div>
    </div> <!-- /pane-adsgen -->

    <!-- ===== TAB: PERSONAL LINK (Bio Link) ===== -->
    <div class="ubp-pane" id="pane-personal">
      <?php
        global $wpdb, $user, $aff_id;
        $aff_id = get_current_user_id();
        $tBP = $wpdb->prefix.'ubp_bio_profile';
        $tBL = $wpdb->prefix.'ubp_bio_links';

        $bio = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tBP WHERE user_id=%d", $aff_id));

        $all_links = get_posts(['post_type'=>'ubp_afflink','numberposts'=>-1,'post_status'=>'publish']);
        $enabled_map = [];
        if ($bio) {
          $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tBL WHERE user_id=%d", $aff_id));
          foreach($rows as $r){ $enabled_map[ (int)$r->link_post_id ] = $r; }
        }
        $bio_slug = $bio ? $bio->slug : '';
        $bio_url  = $bio_slug ? home_url('/bio/'.$bio_slug) : '';
        $qr_url   = $bio_slug ? add_query_arg(['slug'=>$bio_slug], home_url('/ubp-bio-qr')) : '';
      ?>

      <div class="ubp-card">
        <div class="ubp-h3">Pengaturan Bio Link</div>
        <div class="ubp-stack cols-2">
          <div>
            <label>Slug (URL):</label>
            <input class="field" id="bio_slug" type="text" placeholder="mis. agiel" value="<?php echo esc_attr($bio_slug); ?>">
            <label class="small">Halaman publik Anda: <?php echo $bio_slug ? esc_html($bio_url) : '(belum dibuat)'; ?></label>
          </div>
          <div>
            <label>Display Name</label>
            <input class="field" id="bio_display" type="text" value="<?php echo esc_attr($bio->display_name ?? $user->display_name); ?>">
          </div>
          <div>
            <label>Headline / CTA</label>
            <input class="field" id="bio_headline" type="text" value="<?php echo esc_attr($bio->headline ?? ''); ?>">
            <label class="small">Contoh: “Info Umroh Hemat + Bimbingan Penuh”</label>
          </div>
          <div>
            <label>Avatar URL (opsional)</label>
            <input class="field" id="bio_avatar" type="url" value="<?php echo esc_attr($bio->avatar_url ?? ''); ?>">
          </div>
        </div>
        <div class="ubp-row" style="margin-top:12px">
          <button class="ubp-btn primary" id="bioSaveBtn">Save</button>
          <?php if ($bio_slug): ?>
            <a class="ubp-btn ghost" id="bioPreviewBtn" href="<?php echo esc_url($bio_url); ?>" target="_blank" rel="noopener">Preview</a>
            <a class="ubp-btn ghost" id="bioQrBtn" href="<?php echo esc_url($qr_url); ?>" target="_blank" rel="noopener" download="qr-<?php echo esc_attr($bio_slug); ?>.png">Download QR</a>
            <button class="ubp-btn ghost" data-copy="<?php echo esc_attr($bio_url); ?>">Copy Link</button>
          <?php endif; ?>
        </div>
      </div>

      <div class="ubp-section">
        <div class="ubp-card">
          <div class="ubp-h3">Pilih Link yang Ditampilkan</div>
          <div class="ubp-row">
            <button class="ubp-btn ghost" id="bioSyncLinks">Sinkron dari Link Global</button>
            <span style="color:#6b7280;font-size:12px">Klik sinkron jika admin menambah link baru.</span>
          </div>
          <div class="ubp-stack" id="bioLinksList" style="margin-top:12px">
            <?php if (!empty($all_links)): foreach($all_links as $lk):
              $post_id = (int)$lk->ID;
              $row     = $enabled_map[$post_id] ?? null;
              $enabled = $row ? (int)$row->is_enabled === 1 : 0;
              $label   = $row && $row->label_override ? $row->label_override : $lk->post_title;
              $aff_url = home_url("/go/$aff_id/{$lk->post_name}");
            ?>
            <div class="ubp-card" data-postid="<?php echo $post_id; ?>">
              <div class="ubp-row" style="justify-content:space-between;align-items:center">
                <div style="min-width:0">
                  <div style="font-weight:700; font-size:15px;"><?php echo esc_html($lk->post_title); ?></div>
                  <div class="ubp-mono ubp-wrap" style="color:#6b7280;font-size:12px;"><?php echo esc_html($aff_url); ?></div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:#374151">
                  <span>Tampilkan</span>
                  <label class="switch">
                    <input type="checkbox" class="bioToggle" <?php checked($enabled,1); ?>>
                    <span class="slider"></span>
                  </label>
                </div>
              </div>
              <div style="margin-top:8px">
                <label>Label Override (opsional)</label>
                <input type="text" class="field bioLabel" value="<?php echo esc_attr($label); ?>">
              </div>
            </div>
            <?php endforeach; else: ?>
              <div class="ubp-card">Belum ada link global. Tambahkan di menu <b>Affiliate Links</b> (admin).</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div> <!-- /pane-personal -->
  </div> <!-- /#ubpApp -->

  <!-- Toast -->
  <div id="ubpToast" style="position:fixed;left:50%;transform:translateX(-50%);bottom:20px;background:#111827;color:#fff;padding:10px 14px;border-radius:10px;font-size:13px;opacity:0;pointer-events:none;transition:.25s;z-index:9999;">Tersalin!</div>
  <?php
  // FLAG agar JS footer dimuat
  $GLOBALS['UBP_AFF_TOOLS_NEED_JS'] = [
    'ajax'     => admin_url('admin-ajax.php'),
    'nonce'    => wp_create_nonce('ubp_save_pixel'),
    'affParam' => UBP_AFF_PARAM,
    'pidParam' => UBP_PID_PARAM,
    'affId'    => $aff_id,
    'intake'   => $intake,
    'affcard'  => $affcard,
    'site'     => $site,
  ];
  return ob_get_clean();
});

/// BEGIN PATCH: AJAX — BIO PROFILE & LINKS
add_action('wp_ajax_ubp_bio_save_profile', function(){
  if (!is_user_logged_in()) wp_send_json_error('Unauthorized', 401);
  $uid = get_current_user_id();
  global $wpdb; $t = $wpdb->prefix.'ubp_bio_profile';

  $slug      = sanitize_title($_POST['slug'] ?? '');
  $display   = sanitize_text_field($_POST['display'] ?? '');
  $headline  = sanitize_text_field($_POST['headline'] ?? '');
  $avatar    = esc_url_raw($_POST['avatar'] ?? '');

  if (!$slug) wp_send_json_error('Slug wajib diisi', 400);

  // Cek unik slug (boleh sama hanya jika milik user ini)
  $exists = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE slug=%s", $slug));
  if ($exists && (int)$exists->user_id !== (int)$uid) wp_send_json_error('Slug sudah dipakai', 409);

  $now = current_time('mysql');
  $mine = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE user_id=%d", $uid));
  if ($mine) {
    $wpdb->update($t, [
      'slug'=>$slug, 'display_name'=>$display, 'headline'=>$headline, 'avatar_url'=>$avatar,
      'updated_at'=>$now
    ], ['id'=>$mine->id]);
  } else {
    $wpdb->insert($t, [
      'user_id'=>$uid, 'slug'=>$slug, 'display_name'=>$display, 'headline'=>$headline, 'avatar_url'=>$avatar,
      'is_active'=>1, 'updated_at'=>$now
    ]);
  }
  wp_send_json_success(['slug'=>$slug, 'url'=>home_url('/bio/'.$slug), 'qr'=>add_query_arg(['slug'=>$slug], home_url('/ubp-bio-qr'))]);
});

add_action('wp_ajax_ubp_bio_sync_links', function(){
  if (!is_user_logged_in()) wp_send_json_error('Unauthorized', 401);
  $uid = get_current_user_id();
  global $wpdb; $t = $wpdb->prefix.'ubp_bio_links';

  $all = get_posts(['post_type'=>'ubp_afflink','numberposts'=>-1,'post_status'=>'publish','fields'=>'ids']);
  $now = current_time('mysql');
  foreach($all as $pid){
    $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE user_id=%d AND link_post_id=%d", $uid, $pid));
    if (!$exists){
      $wpdb->insert($t, [
        'user_id'=>$uid,
        'link_post_id'=>(int)$pid,
        'is_enabled'=>0,
        'label_override'=>'',
        'sort_order'=>0,
        'updated_at'=>$now
      ]);
    }
  }
  wp_send_json_success('Synced');
});

add_action('wp_ajax_ubp_bio_save_link', function(){
  if (!is_user_logged_in()) wp_send_json_error('Unauthorized', 401);
  $uid   = get_current_user_id();
  $pid   = (int)($_POST['post_id'] ?? 0);
  $en    = isset($_POST['is_enabled']) && $_POST['is_enabled']=='1' ? 1 : 0;
  $label = sanitize_text_field($_POST['label'] ?? '');
  $sort  = (int)($_POST['sort_order'] ?? 0);

  if (!$pid) wp_send_json_error('post_id invalid', 400);

  global $wpdb; $t = $wpdb->prefix.'ubp_bio_links';
  $now = current_time('mysql');
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE user_id=%d AND link_post_id=%d", $uid, $pid));
  if ($row){
    $wpdb->update($t, [
      'is_enabled'=>$en, 'label_override'=>$label, 'sort_order'=>$sort, 'updated_at'=>$now
    ], ['id'=>$row->id]);
  } else {
    $wpdb->insert($t, [
      'user_id'=>$uid, 'link_post_id'=>$pid, 'is_enabled'=>$en, 'label_override'=>$label,
      'sort_order'=>$sort, 'updated_at'=>$now
    ]);
  }
  wp_send_json_success('Saved');
});
/// END PATCH

/* ===== AJAX: simpan default Pixel per user login ===== */
add_action('wp_ajax_ubp_save_pixel_defaults', function(){
  if (!is_user_logged_in()) { wp_die('Unauthorized'); }
  if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'ubp_save_pixel')) {
    wp_die('Nonce invalid');
  }
  $uid = get_current_user_id();
  update_user_meta($uid, 'ubp_pixel_id',      sanitize_text_field($_POST['pix_id'] ?? ''));
  update_user_meta($uid, 'ubp_pixel_event',   sanitize_text_field($_POST['pix_evt'] ?? 'Lead'));
  update_user_meta($uid, 'ubp_pixel_value',   sanitize_text_field($_POST['pix_val'] ?? ''));
  update_user_meta($uid, 'ubp_pixel_currency',sanitize_text_field($_POST['pix_cur'] ?? 'IDR'));
  update_user_meta($uid, 'ubp_pixel_delay',   sanitize_text_field($_POST['pix_dly'] ?? '300'));
  wp_die('Tersimpan');
});


/// BEGIN PATCH: OPTIONAL SHORTCODE BIO PREVIEW
add_shortcode('ubp_bio_preview', function($atts){
  $a = shortcode_atts(['user_id'=>0], $atts);
  $uid = (int)$a['user_id'] ?: (is_user_logged_in()? get_current_user_id() : 0);
  if (!$uid) return '';
  global $wpdb;
  $t = $wpdb->prefix.'ubp_bio_profile';
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE user_id=%d", $uid));
  if(!$row) return '<em>Bio belum diset.</em>';
  $url = home_url('/bio/'.$row->slug);
  $qr  = add_query_arg(['slug'=>$row->slug], home_url('/ubp-bio-qr'));
  return '<div><a class="button button-primary" href="'.esc_url($url).'" target="_blank">Preview Bio</a> &nbsp; <a class="button" href="'.esc_url($qr).'" download="qr-'.$row->slug.'.png">Download QR</a></div>';
});
/// END PATCH


/* =========================================================
 * FOOTER LOADER — JS untuk Section 8 (Tools + Ads Generator + Prospek)
 * ========================================================= */
add_action('wp_footer', function () {
  if (empty($GLOBALS['UBP_AFF_TOOLS_NEED_JS'])) return; // hanya saat shortcode dipakai
  $cfg = $GLOBALS['UBP_AFF_TOOLS_NEED_JS'];
  $js_cfg = wp_json_encode([
    'ajax'     => esc_url_raw($cfg['ajax']),
    'nonce'    => $cfg['nonce'],
    'affParam' => $cfg['affParam'],
    'pidParam' => $cfg['pidParam'],
    'affId'    => (int)$cfg['affId'],
    'intake'   => esc_url_raw($cfg['intake']),
    'affcard'  => esc_url_raw($cfg['affcard']),
    'site'     => esc_url_raw($cfg['site']),
  ]);
  ?>
  <script id="ubp-aff-tools-inline">
  (function(){
    var CFG = <?php echo $js_cfg; ?>;
    var app = document.getElementById('ubpApp'); if(!app) return;

    function $(id){ return document.getElementById(id); }
    function showToast(msg){
      var t=document.getElementById('ubpToast'); if(!t) return;
      t.textContent=msg||'Tersalin!'; t.style.opacity=1; setTimeout(function(){ t.style.opacity=0; }, 1400);
    }

    // ===== Tabs =====
    app.querySelectorAll('.ubp-tab').forEach(function(tab){
      tab.addEventListener('click', function(){
        app.querySelectorAll('.ubp-tab').forEach(function(x){x.classList.remove('active')});
        app.querySelectorAll('.ubp-pane').forEach(function(x){x.classList.remove('active')});
        tab.classList.add('active');
        var id = '#pane-' + tab.dataset.tab;
        var pane = app.querySelector(id); if(pane) pane.classList.add('active');
      });
    });

    // ===== Copy (link/textarea) =====
    app.addEventListener('click', async function(e){
      var btn=e.target.closest('[data-copy],[data-copy-target]'); if(!btn) return;
      try{
        var text='';
        if(btn.dataset.copy){ text=btn.dataset.copy; }
        else if(btn.dataset.copyTarget){
          var el=document.querySelector(btn.dataset.copyTarget);
          text = el ? ((el.value!==undefined?el.value:el.textContent)||'') : '';
        }
        await navigator.clipboard.writeText(text);
        showToast('Tersalin');
      }catch(_){ showToast('Gagal menyalin'); }
    });

    // ===== Toggle detail prospek =====
    app.addEventListener('click', function(e){
      var t=e.target.closest('.toggle'); if(!t) return;
      var card=t.closest('.ubp-prospect'); if(!card) return;
      var det=card.querySelector('.detail'); if(!det) return;
      var open = det.style.display!=='none';
      det.style.display = open ? 'none':'block';
      t.textContent = !open ? 'Tutup' : 'Detail';
    });

    // ===== Prospek: filter & pagination via AJAX (fragment) =====
    (function(){
      var app = document.getElementById('ubpApp'); if(!app) return;

      var list  = app.querySelector('#ubpProspekList');
      var pager = app.querySelector('#ubpProspekPager');

      function swapProspek(html){
        var tmp = document.createElement('div'); tmp.innerHTML = html;
        var listNew  = tmp.querySelector('#ubpProspekList');
        var pagerNew = tmp.querySelector('#ubpProspekPager');

        if(list && listNew)   list.innerHTML  = listNew.innerHTML;
        if(pager){
          if(pagerNew) pager.innerHTML = pagerNew.innerHTML;
          else pager.innerHTML = '';
        }
      }

      async function fetchProspekFragment(params){
        try{
          var body = new URLSearchParams(Object.assign({ action:'ubp_list_prospek' }, params));
          var res  = await fetch(CFG.ajax, {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            credentials:'same-origin',
            body: body.toString()
          });
          return await res.text();
        }catch(e){
          return '<div id="ubpProspekList" class="ubp-stack"><div class="ubp-card">Gagal memuat data.</div></div>';
        }
      }

      function buildUrl(f, p){
        var url = new URL(location.href);
        url.searchParams.set('tab','prospek');
        url.searchParams.set('f', f || 'all');
        url.searchParams.set('p', String(p || 1));
        return url.toString();
      }

      async function applyFilter(status){
        var f = status || 'all';
        var p = 1;
        var html = await fetchProspekFragment({ f:f, p:p });
        swapProspek(html);
        history.pushState(null, '', buildUrl(f,p));
      }

      async function applyPage(f, p){
        var html = await fetchProspekFragment({ f:f||'all', p:p||1 });
        swapProspek(html);
        history.pushState(null, '', buildUrl(f||'all', p||1));
      }

      // Kapsul filter (delegation)
      app.addEventListener('click', function(ev){
        var chip = ev.target.closest('.js-prospek-filter'); if(!chip) return;
        ev.preventDefault();
        var st = chip.getAttribute('data-filter') || 'all';
        applyFilter(st);
      });

      // Pager (delegation)
      app.addEventListener('click', function(ev){
        var a = ev.target.closest('a.ubp-pg'); if(!a) return;
        ev.preventDefault();
        var f = a.getAttribute('data-f') || 'all';
        var p = parseInt(a.getAttribute('data-p') || '1', 10);
        if (isNaN(p) || p < 1) p = 1;
        applyPage(f, p);
      });

      // Back/Forward browser
      window.addEventListener('popstate', function(){
        var url  = new URL(location.href);
        var f    = url.searchParams.get('f') || 'all';
        var p    = parseInt(url.searchParams.get('p') || '1', 10);
        if(isNaN(p) || p < 1) p = 1;
        fetchProspekFragment({ f:f, p:p }).then(swapProspek);
      });

      // Aktifkan tab awal dari URL (?tab=prospek)
      (function(){
        var params = new URLSearchParams(location.search);
        var t = params.get('tab');
        if(!t) return;
        var currentActive = app.querySelector('.ubp-tab.active');
        var targetTab = app.querySelector('.ubp-tab[data-tab="'+t+'"]');
        var targetPane = app.querySelector('#pane-' + t);
        if (targetTab && targetPane) {
          if(currentActive) currentActive.classList.remove('active');
          app.querySelectorAll('.ubp-pane').forEach(function(x){x.classList.remove('active')});
          targetTab.classList.add('active');
          targetPane.classList.add('active');
        }
      })();
    })();

    // ===== PERSONAL LINK — Save Profile, Sync Links, Auto Save =====
    (function(){
      function postAjax(data){
        return fetch(CFG.ajax, {
          method:'POST',
          headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body: new URLSearchParams(data)
        }).then(r=>r.json());
      }

      var btnSave = document.getElementById('bioSaveBtn');
      if (btnSave){
        btnSave.addEventListener('click', function(e){
          e.preventDefault();
          var slug     = (document.getElementById('bio_slug')||{}).value || '';
          var display  = (document.getElementById('bio_display')||{}).value || '';
          var headline = (document.getElementById('bio_headline')||{}).value || '';
          var avatar   = (document.getElementById('bio_avatar')||{}).value || '';
          if(!slug){ showToast('Slug wajib diisi'); return; }

          postAjax({ action:'ubp_bio_save_profile', slug, display, headline, avatar })
          .then(function(res){
            if(res && res.success){
              // Update label kecil + tombol preview/QR
              var labelEls = document.querySelectorAll('label.small');
              labelEls.forEach(function(el){
                if(el.textContent.indexOf('Halaman publik Anda:')>-1){
                  el.textContent = 'Halaman publik Anda: ' + (res.data.url || '');
                }
              });
              var prev = document.getElementById('bioPreviewBtn');
              var qr   = document.getElementById('bioQrBtn');
              if(!prev){
                var cont = btnSave.parentElement;
                prev = document.createElement('a');
                prev.id='bioPreviewBtn'; prev.className='ubp-btn ghost';
                prev.target='_blank'; prev.rel='noopener'; prev.textContent='Preview';
                cont.appendChild(prev);
                qr = document.createElement('a');
                qr.id='bioQrBtn'; qr.className='ubp-btn ghost';
                qr.target='_blank'; qr.rel='noopener'; qr.textContent='Download QR';
                cont.appendChild(qr);
              }
              prev.href = res.data.url;
              qr.href   = res.data.qr;
              qr.setAttribute('download','qr-'+(res.data.slug||slug)+'.png');

              showToast('Profil tersimpan');
            }else{
              showToast((res && res.data) || 'Gagal menyimpan');
            }
          }).catch(function(){ showToast('Gagal menyimpan'); });
        });
      }

      var btnSync = document.getElementById('bioSyncLinks');
      if (btnSync){
        btnSync.addEventListener('click', function(e){
          e.preventDefault();
          postAjax({ action:'ubp_bio_sync_links' })
          .then(function(res){
            if(res && res.success){
              showToast('Sinkron berhasil'); 
              setTimeout(function(){ location.reload(); }, 600);
            }else{
              showToast('Gagal sinkron');
            }
          }).catch(function(){ showToast('Gagal sinkron'); });
        });
      }

      // Auto save tiap link (toggle + label)
      (function(){
        function saveOne(card){
          var pid = card.getAttribute('data-postid');
          var en  = card.querySelector('.bioToggle')?.checked ? '1' : '0';
          var lbl = card.querySelector('.bioLabel')?.value || '';
          return postAjax({ action:'ubp_bio_save_link', post_id:pid, is_enabled:en, label:lbl, sort_order:0 });
        }

        var list = document.getElementById('bioLinksList');
        if(!list) return;

        list.addEventListener('change', function(e){
          var t=e.target;
          if(!t.classList.contains('bioToggle')) return;
          var card=t.closest('.ubp-card'); if(!card) return;
          saveOne(card).then(d=>showToast(d?.success?'Tersimpan':'Gagal menyimpan')).catch(()=>showToast('Gagal menyimpan'));
        });

        var timers = new WeakMap();
        list.addEventListener('input', function(e){
          var t=e.target;
          if(!t.classList.contains('bioLabel')) return;
          var card=t.closest('.ubp-card'); if(!card) return;
          clearTimeout(timers.get(card));
          var tm = setTimeout(function(){
            saveOne(card).then(d=>showToast(d?.success?'Tersimpan':'Gagal menyimpan')).catch(()=>showToast('Gagal menyimpan'));
          }, 600);
          timers.set(card, tm);
        });
        list.addEventListener('blur', function(e){
          var t=e.target;
          if(!t.classList.contains('bioLabel')) return;
          var card=t.closest('.ubp-card'); if(!card) return;
          clearTimeout(timers.get(card));
          saveOne(card).then(d=>showToast(d?.success?'Tersimpan':'Gagal menyimpan')).catch(()=>showToast('Gagal menyimpan'));
        }, true);
      })();
    })();

    // ===== Generate Bundle =====
    function genBasePixelHead(id){
      id = String(id||'').trim();
      if (!id) return '';
      return (
        "<!-- Meta Pixel Base Code (tempel di <head>) -->\n" +
        "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod? n.callMethod.apply(n,arguments):n.queue.push(arguments)}; if(!f._fbq)f._fbq=n; n.push=n;n.loaded=!0;n.version=\"2.0\"; n.queue=[];t=b.createElement(e);t.async=!0; t.src=v;s=b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t,s)}(window,document,\"script\",\"https://connect.facebook.net/en_US/fbevents.js\"); fbq(\"init\",\""+id+"\"); fbq(\"track\",\"PageView\");<\/script>\n" +
        "<noscript><img height=\"1\" width=\"1\" style=\"display:none\" src=\"https://www.facebook.com/tr?id="+id+"&ev=PageView&noscript=1\"/></noscript>"
      );
    }
    function genEventScript(evt, dly, val, cur){
      var delay = isNaN(parseInt(dly,10)) ? 300 : parseInt(dly,10);
      var valueLine = (String(val||'').trim()!=='')
        ? "value: "+val+", currency: \""+(cur||'IDR')+"\", "
        : "";
      return (
"<!-- Meta Pixel Event (auto-fire sebelum form submit) -->\n" +
"<script>(function(){\n" +
"  var DLY="+delay+";\n" +
"  function fireEvt(cb){\n" +
"    try{\n" +
"      if(typeof fbq===\"function\"){\n" +
"        var A=document.getElementById('"+CFG.affParam+"');\n" +
"        var P=document.getElementById('"+CFG.pidParam+"');\n" +
"        var aff=(A && A.value) ? A.value : \""+String(CFG.affId)+"\";\n" +
"        var pid=(P && P.value) ? P.value : \"\";\n" +
"        var params={ "+valueLine+" affiliate_id: aff||undefined, pid: pid||undefined };\n" +
"        var opts={ eventID: pid||undefined };\n" +
"        fbq(\"track\",\""+(evt||'Lead')+"\", params, opts);\n" +
"      }\n" +
"    }catch(e){}\n" +
"    setTimeout(function(){ try{cb&&cb();}catch(e){} }, DLY);\n" +
"  }\n" +
"  try{\n" +
"    var forms=document.querySelectorAll('form[action^=\"" + CFG.intake + "\"]');\n" +
"    forms.forEach(function(f){\n" +
"      f.addEventListener('submit', function(ev){ ev.preventDefault(); var frm=this; fireEvt(function(){ frm.submit(); }); }, {capture:true});\n" +
"    });\n" +
"  }catch(e){}\n" +
"})();<\/script>"
      );
    }
    function genFormHtml(opts){
      var fields = [];
      fields.push('  <label>Nama*</label>');
      fields.push('  <input type="text" name="nama" required style="width:100%;padding:12px;margin:8px 0;border-radius:10px;border:1px solid #e5e7eb;">');
      if(opts.showEmail){
        fields.push('  <label>Email</label>');
        fields.push('  <input type="email" name="email" style="width:100%;padding:12px;margin:8px 0;border-radius:10px;border:1px solid #e5e7eb;">');
      }
      if(opts.showWa){
        fields.push('  <label>Nomor WA</label>');
        fields.push('  <input type="tel" name="wa" placeholder="08xxxxxxxxxx" style="width:100%;padding:12px;margin:8px 0;border-radius:10px;border:1px solid #e5e7eb;">');
      }

      var afterHidden = (opts.after && ['group','url','hub','none'].indexOf(opts.after)!==-1)
        ? '  <input type="hidden" name="after" value="'+opts.after+'">\n' : '';

      var html =
        "<form method=\"post\" action=\""+CFG.intake+"\" style=\"max-width:520px;margin:auto;\">\n" +
        "  <input type=\"hidden\" name=\""+CFG.affParam+"\" id=\""+CFG.affParam+"\">\n" +
        "  <input type=\"hidden\" name=\""+CFG.pidParam+"\" id=\""+CFG.pidParam+"\">\n" +
             afterHidden +
        "  <input type=\"hidden\" name=\"group\" value=\""+(opts.group||'')+"\">\n" +
        "  <input type=\"hidden\" name=\"redirect\" value=\""+(opts.redirect||'')+"\">\n" +
        "  <input type=\"hidden\" name=\"hub\" value=\""+(opts.hub||'')+"\">\n" +
        "  <input type=\"hidden\" name=\"send_wa\" value=\"1\">\n\n" +
             fields.join("\n") + "\n\n" +
        "  <button type=\"submit\" class=\"ubp-btn-submit\" style=\"width:100%;padding:14px;border:0;border-radius:12px;background:#3b82f6;color:#fff;font-weight:700;\">"+(opts.btn||'Daftar')+"</button>\n" +
        "  <p style=\"font-size:12px;color:#6b7280;margin-top:8px\">Dengan menekan tombol, Anda menyetujui kami menghubungi via WhatsApp untuk mengirim tautan dan info lanjutan.</p>\n" +
        "</form>\n\n" +
        "<!-- Inject aff/pid dari URL atau fallback ke ID mitra pembuat snippet -->\n" +
        "<script>(function(){ var qs=new URLSearchParams(location.search); var a=qs.get('"+CFG.affParam+"')||'"+String(CFG.affId)+"'; var p=qs.get('"+CFG.pidParam+"')||''; var A=document.getElementById('"+CFG.affParam+"'); var P=document.getElementById('"+CFG.pidParam+"'); if(A) A.value=a; if(P) P.value=p; })();<\/script>\n\n" +
        "<!-- Affiliate Card (iframe, tidak butuh CORS) -->\n" +
        "<div id=\"affInfoCard\" style=\"margin-top:12px;\"></div>\n" +
        "<script>(function(){ var qs=new URLSearchParams(location.search); var aff=qs.get('"+CFG.affParam+"')||'"+String(CFG.affId)+"'; var ifr=document.createElement('iframe'); ifr.src='"+CFG.affcard+"?aff='+encodeURIComponent(aff); ifr.style.width='100%'; ifr.style.maxWidth='420px'; ifr.style.height='100px'; ifr.style.border='0'; ifr.style.borderRadius='12px'; ifr.style.overflow='hidden'; var root=document.getElementById('affInfoCard'); if(root) root.appendChild(ifr); })();<\/script>";

      return html;
    }
    function genBundle(){
      var pixId = ($('gen_pix_id')||{}).value||'';
      var evt   = ($('gen_pix_evt')||{}).value||'Lead';
      var dly   = ($('gen_pix_dly')||{}).value||'300';
      var val   = ($('gen_pix_val')||{}).value||'';
      var cur   = ($('gen_pix_cur')||{}).value||'IDR';
      var showE = $('gen_show_email') ? !!$('gen_show_email').checked : true;
      var showW = $('gen_show_wa') ? !!$('gen_show_wa').checked : true;
      var btn   = ($('gen_btn_label')||{}).value||'Daftar';
      var after = ($('gen_after')||{}).value||'hub';
      var group = ($('gen_group')||{}).value||'';
      var redir = ($('gen_redirect')||{}).value||'';
      var hub   = ($('gen_hub')||{}).value||'';

      var formHtml  = genFormHtml({showEmail:showE, showWa:showW, btn:btn, after:after, group:group, redirect:redir, hub:hub});
      var evtScript = genEventScript(evt, dly, val, cur);

      if ($('code_head')) $('code_head').value = genBasePixelHead(pixId);
      if ($('code_evt'))  $('code_evt').value  = evtScript;

      var headInline = genBasePixelHead(pixId);
      var bundle = (headInline ? "<!-- Base Pixel (inline; opsional—hapus jika head Anda sudah ada fbq) -->\n" + headInline + "\n\n" : "")
                 + formHtml + "\n\n" + evtScript;

      if ($('code_bundle')) $('code_bundle').value = bundle;
    }
    var genBtn = $('btnGen'); if(genBtn) genBtn.addEventListener('click', function(e){ e.preventDefault(); genBundle(); showToast('Generated'); });
    genBundle();

    var saveBtn = $('btnSaveDefaults');
    if(saveBtn) saveBtn.addEventListener('click', async function(e){
      e.preventDefault();
      try{
        var res = await fetch(CFG.ajax, {
          method:'POST',
          headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body: new URLSearchParams({
            action:'ubp_save_pixel_defaults',
            _wpnonce: CFG.nonce,
            pix_id:  ($('gen_pix_id')||{}).value || '',
            pix_evt: ($('gen_pix_evt')||{}).value || 'Lead',
            pix_val: ($('gen_pix_val')||{}).value || '',
            pix_cur: ($('gen_pix_cur')||{}).value || 'IDR',
            pix_dly: ($('gen_pix_dly')||{}).value || '300'
          })
        });
        var t = await res.text(); showToast(t||'Tersimpan');
      }catch(_){ showToast('Gagal menyimpan'); }
    });
  })();
  </script>
  <?php
});


/* =========================================================
 * SECTION 9 — PENANDA "JOIN GRUP" (opsional)
 * ========================================================= */
add_action('init', function(){
  add_rewrite_rule('^joined/?$', 'index.php?ubp_joined=1', 'top');
});
add_filter('query_vars', function($qv){ $qv[]='ubp_joined'; return $qv; });
add_action('template_redirect', function(){
  if (get_query_var('ubp_joined')) {
    global $wpdb; $t = $wpdb->prefix.'ubp_prospek';
    $pid = sanitize_text_field($_GET[UBP_PID_PARAM] ?? '');
    if ($pid) $wpdb->update($t, ['status'=>'group','ts_last'=>current_time('mysql')], ['pid'=>$pid]);
    wp_redirect(home_url('/')); exit;
  }
});


/* =========================================================
 * SECTION 10 — ADMIN SUBMENU: Shortcode Helper
 * ========================================================= */
add_action('admin_menu', function(){
  add_submenu_page(
    'edit.php?post_type=ubp_afflink',
    'Shortcodes',
    'Shortcodes',
    'manage_options',
    'ubp-aff-shortcodes',
    'ubp_aff_shortcode_page',
    20
  );
});

function ubp_aff_shortcode_page(){
  $sc1 = '[ubp_lead_form group="https://chat.whatsapp.com/XXXX"]';
  $sc2 = '[ubp_lead_form after="url" redirect="https://domain.com/terimakasih"]';
  $sc3 = '[ubp_lead_form after="hub"]';
  $sc4 = '[ubp_lead_form after="none"]';
  $sc5 = '[ubp_lead_form send_wa="0" after="hub"]';
  $sc6 = '[ubp_affiliate_tools]';
  ?>
  <div class="wrap">
    <h1 style="margin-bottom:8px;">Shortcodes</h1>
    <p style="margin-top:0;color:#555;max-width:720px;">
      Halaman ini menampilkan <b>shortcode siap pakai</b> untuk form lead dan dashboard mitra.
      Klik tombol <b>Copy</b> untuk menyalin. Setelah itu tempel di halaman/section yang Anda inginkan.
    </p>

    <div style="display:grid;gap:16px;max-width:900px;">
      <div style="border:1px solid #e5e7eb;border-radius:12px;padding:16px;background:#fff;">
        <h2 style="margin:0 0 6px;font-size:18px;">Dashboard Mitra</h2>
        <p style="margin:0 0 10px;color:#555;">Tampilkan daftar link affiliate, progress funnel, dan daftar prospek milik mitra yang login.</p>
        <code id="sc6" style="display:inline-block;background:#f3f4f6;padding:6px 10px;border-radius:6px;"><?php echo esc_html($sc6); ?></code>
        <button class="button button-primary" data-copy-target="#sc6" style="margin-left:6px;">Copy</button>
      </div>

      <div style="border:1px solid #e5e7eb;border-radius:12px;padding:16px;background:#fff;">
        <h2 style="margin:0 0 6px;font-size:18px;">Lead Form → Redirect ke Grup WA</h2>
        <p style="margin:0 0 10px;color:#555;">Setelah submit, user diarahkan ke link WhatsApp Group (default).</p>
        <code id="sc1" style="display:inline-block;background:#f3f4f6;padding:6px 10px;border-radius:6px;"><?php echo esc_html($sc1); ?></code>
        <button class="button button-primary" data-copy-target="#sc1" style="margin-left:6px;">Copy</button>
      </div>

      <div style="border:1px solid #e5e7eb;border-radius:12px;padding:16px;background:#fff;">
        <h2 style="margin:0 0 6px;font-size:18px;">Lead Form → Redirect ke Halaman</h2>
        <p style="margin:0 0 10px;color:#555;">Setelah submit, user diarahkan ke halaman kustom (mis. /terimakasih).</p>
        <code id="sc2" style="display:inline-block;background:#f3f4f6;padding:6px 10px;border-radius:6px;"><?php echo esc_html($sc2); ?></code>
        <button class="button button-primary" data-copy-target="#sc2" style="margin-left:6px;">Copy</button>
      </div>

      <div style="border:1px solid #e5e7eb;border-radius:12px;padding:16px;background:#fff;">
        <h2 style="margin:0 0 6px;font-size:18px;">Lead Form → Redirect ke Hub (/continue)</h2>
        <p style="margin:0 0 10px;color:#555;">Setelah submit, user diarahkan ke halaman Hub.</p>
        <code id="sc3" style="display:inline-block;background:#f3f4f6;padding:6px 10px;border-radius:6px;"><?php echo esc_html($sc3); ?></code>
        <button class="button button-primary" data-copy-target="#sc3" style="margin-left:6px;">Copy</button>
      </div>

      <div style="border:1px solid #e5e7eb;border-radius:12px;padding:16px;background:#fff;">
        <h2 style="margin:0 0 6px;font-size:18px;">Lead Form → Tanpa Redirect</h2>
        <p style="margin:0 0 10px;color:#555;">Setelah submit, tampil kartu sukses.</p>
        <code id="sc4" style="display:inline-block;background:#f3f4f6;padding:6px 10px;border-radius:6px;"><?php echo esc_html($sc4); ?></code>
        <button class="button button-primary" data-copy-target="#sc4" style="margin-left:6px;">Copy</button>
      </div>

      <div style="border:1px solid #e5e7eb;border-radius:12px;padding:16px;background:#fff;">
        <h2 style="margin:0 0 6px;font-size:18px;">Lead Form → Tanpa Kirim WA</h2>
        <p style="margin:0 0 10px;color:#555;">Cocok untuk uji A/B.</p>
        <code id="sc5" style="display:inline-block;background:#f3f4f6;padding:6px 10px;border-radius:6px;"><?php echo esc_html($sc5); ?></code>
        <button class="button button-primary" data-copy-target="#sc5" style="margin-left:6px;">Copy</button>
      </div>
    </div>

    <hr style="margin:24px 0;">
    <script>
      (function(){
        document.querySelectorAll('button[data-copy-target]').forEach(function(btn){
          btn.addEventListener('click', async function(){
            try{
              var selector = btn.getAttribute('data-copy-target');
              var codeEl = document.querySelector(selector);
              var text = codeEl ? codeEl.textContent : '';
              await navigator.clipboard.writeText(text);
              btn.textContent = 'Copied!';
              setTimeout(()=>btn.textContent='Copy', 1200);
            }catch(e){ alert('Gagal menyalin shortcode'); }
          });
        });
      })();
    </script>
  </div>
  <?php
}


/* =========================================================
 * SECTION 11 — PUBLIC INTAKE ENDPOINT
 * ========================================================= */

// (Opsional) API Key intake
if (!defined('UBP_INTAKE_KEY')) define('UBP_INTAKE_KEY', '');

add_action('init', function(){
  add_rewrite_rule('^ubp-intake/?$', 'index.php?ubp_intake=1', 'top');
});
add_filter('query_vars', function($qv){ $qv[]='ubp_intake'; return $qv; });

add_action('template_redirect', function(){
  if (!get_query_var('ubp_intake')) return;

  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { status_header(204); exit; }
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { status_header(405); echo 'Method Not Allowed'; exit; }

  if (UBP_INTAKE_KEY) {
    $key = $_POST['key'] ?? '';
    if (!$key || !hash_equals(UBP_INTAKE_KEY, $key)) { status_header(403); echo 'Forbidden'; exit; }
  }

  $nama    = sanitize_text_field($_POST['nama']  ?? '');
  $email   = sanitize_email($_POST['email'] ?? '');
  $wa_in   = $_POST['wa'] ?? '';
  $wa      = $wa_in !== '' ? ubpac_normalize_wa($wa_in) : '';
  $aff     = (int)($_POST[UBP_AFF_PARAM] ?? 0);
  $pid     = sanitize_text_field($_POST[UBP_PID_PARAM] ?? '');
  $link_id = isset($_POST['link_id']) ? (int)$_POST['link_id'] : 0;

  $errors = [];
  if ($nama === '') $errors[] = 'Nama wajib diisi';
  if ($email === '' && $wa === '') $errors[] = 'Isi minimal Email atau Nomor WA';
  if ($pid === '')  $pid = ubpac_generate_pid();

  if (!empty($errors)) { status_header(400); header('Content-Type: text/plain; charset=utf-8'); echo 'Error: '.implode('; ', $errors); exit; }

  // Defaults
  $after_default    = get_option('ubp_aff_after', 'hub');
  $group_default    = get_option('ubp_aff_group', UBP_WA_GROUP_URL);
  $redirect_default = get_option('ubp_aff_redirect', home_url('/terimakasih'));
  $hub_default      = get_option('ubp_aff_hub', home_url('/continue'));
  $sendwa_default   = '1';

  $after   = $after_default;
  $group   = $group_default;
  $redirect= $redirect_default;
  $hub     = $hub_default;
  $send_wa = ($sendwa_default==='1');

  if ($link_id) {
    $after_link    = get_post_meta($link_id, '_ubp_intake_after', true);
    $group_link    = get_post_meta($link_id, '_ubp_intake_group', true);
    $redirect_link = get_post_meta($link_id, '_ubp_intake_redirect', true);
    $hub_link      = get_post_meta($link_id, '_ubp_intake_hub', true);
    $sendwa_link   = get_post_meta($link_id, '_ubp_intake_send_wa', true);

    if ($after_link !== '') $after = $after_link;
    if (!empty($group_link))    $group = $group_link;
    if (!empty($redirect_link)) $redirect = $redirect_link;
    if (!empty($hub_link))      $hub = $hub_link;
    if ($sendwa_link==='0' || $sendwa_link==='1') $send_wa = ($sendwa_link==='1');
  }

  if (isset($_POST['after']) && in_array($_POST['after'], ['group','url','hub','none'], true)) $after = $_POST['after'];
  if (isset($_POST['group']) && $_POST['group']!=='')     $group = esc_url_raw($_POST['group']);
  if (isset($_POST['redirect']) && $_POST['redirect']!=='') $redirect = esc_url_raw($_POST['redirect']);
  if (isset($_POST['hub']) && $_POST['hub']!=='')         $hub = esc_url_raw($_POST['hub']);
  if (isset($_POST['send_wa']))                           $send_wa = ($_POST['send_wa']==='1');

  global $wpdb; $t = $wpdb->prefix.'ubp_prospek';
  $now = current_time('mysql');
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE pid=%s", $pid));
  if ($row) {
    $wpdb->update($t, [
      'affiliate_id'=>$aff, 'nama'=>$nama, 'email'=>$email, 'wa'=>$wa,
      'status'=>'lead', 'ts_last'=>$now
    ], ['pid'=>$pid]);
  } else {
    $wpdb->insert($t, [
      'pid'=>$pid, 'affiliate_id'=>$aff, 'nama'=>$nama, 'email'=>$email, 'wa'=>$wa,
      'status'=>'lead', 'ts_first'=>$now, 'ts_last'=>$now
    ]);
  }

  ubpac_set_cookie(UBP_COOKIE_PID, $pid);
  ubpac_set_cookie(UBP_COOKIE_AFF, $aff);

  if ($send_wa && $wa) {
    $hub_url = add_query_arg([UBP_PID_PARAM=>$pid], $hub ?: home_url('/continue'));
    $msg = "Halo $nama, terima kasih sudah mendaftar.\n"
         . "Ini tautan pribadi Anda: $hub_url\n"
         . "Anda bisa lanjut daftar akun / cek materi / beli produk kapan saja.";
    ubpac_send_wa($wa, $msg);
  }

  if ($after === 'group' && $group) {
    wp_redirect($group); exit;
  } elseif ($after === 'url' && $redirect) {
    wp_redirect($redirect); exit;
  } elseif ($after === 'hub') {
    $hub_url = add_query_arg([UBP_PID_PARAM=>$pid], $hub ?: home_url('/continue'));
    wp_redirect($hub_url); exit;
  } else {
    status_header(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo "OK: lead diterima (PID: $pid)"; exit;
  }
});


/* =========================================================
 * SECTION 12 — ADMIN SETTINGS: Redirect Default + Form Defaults
 * ========================================================= */
add_action('admin_menu', function(){
  add_submenu_page(
    'edit.php?post_type=ubp_afflink',
    'Affiliate Settings',
    'Settings',
    'manage_options',
    'ubp-aff-settings',
    'ubp_aff_settings_page',
    30
  );
});

function ubp_aff_settings_page(){
  if (isset($_POST['ubp_aff_save'])) {
    check_admin_referer('ubp_aff_settings');
    update_option('ubp_aff_after', sanitize_text_field($_POST['ubp_aff_after']));
    update_option('ubp_aff_group', esc_url_raw($_POST['ubp_aff_group']));
    update_option('ubp_aff_redirect', esc_url_raw($_POST['ubp_aff_redirect']));
    update_option('ubp_aff_hub', esc_url_raw($_POST['ubp_aff_hub']));
    update_option('ubp_form_show_email', isset($_POST['ubp_form_show_email']) ? '1' : '0');
    update_option('ubp_form_show_wa',    isset($_POST['ubp_form_show_wa'])    ? '1' : '0');
    update_option('ubp_form_button', sanitize_text_field($_POST['ubp_form_button']));
    echo '<div class="updated"><p>Settings updated.</p></div>';
  }
  $after    = get_option('ubp_aff_after', 'hub');
  $group    = get_option('ubp_aff_group', UBP_WA_GROUP_URL);
  $redirect = get_option('ubp_aff_redirect', home_url('/terimakasih'));
  $hub      = get_option('ubp_aff_hub', home_url('/continue'));

  $show_email = get_option('ubp_form_show_email', '1');
  $show_wa    = get_option('ubp_form_show_wa', '1');
  $btn_label  = get_option('ubp_form_button', 'Daftar');
  ?>
  <div class="wrap">
    <h1>Affiliate Settings</h1>
    <form method="post">
      <?php wp_nonce_field('ubp_aff_settings'); ?>

      <h2 class="title">Public Intake Redirect (Default)</h2>
      <table class="form-table">
        <tr>
          <th scope="row">Redirect Default</th>
          <td>
            <select name="ubp_aff_after">
              <option value="group" <?php selected($after,'group'); ?>>Grup WhatsApp</option>
              <option value="url"   <?php selected($after,'url');   ?>>Custom URL</option>
              <option value="hub"   <?php selected($after,'hub');   ?>>Halaman Hub (/continue)</option>
              <option value="none"  <?php selected($after,'none');  ?>>Tidak Redirect</option>
            </select>
          </td>
        </tr>
        <tr><th scope="row">Link Grup Default</th>
          <td><input type="url" name="ubp_aff_group" value="<?php echo esc_attr($group); ?>" style="width:100%"></td></tr>
        <tr><th scope="row">Redirect URL Default</th>
          <td><input type="url" name="ubp_aff_redirect" value="<?php echo esc_attr($redirect); ?>" style="width:100%"></td></tr>
        <tr><th scope="row">Hub URL Default</th>
          <td><input type="url" name="ubp_aff_hub" value="<?php echo esc_attr($hub); ?>" style="width:100%"></td></tr>
      </table>

      <h2 class="title" style="margin-top:24px;">Form Defaults</h2>
      <table class="form-table">
        <tr>
          <th scope="row">Field yang Ditampilkan</th>
          <td>
            <label><input type="checkbox" name="ubp_form_show_email" value="1" <?php checked($show_email,'1'); ?>> Tampilkan Email</label><br>
            <label><input type="checkbox" name="ubp_form_show_wa" value="1" <?php checked($show_wa,'1'); ?>> Tampilkan Nomor WA</label>
            <p class="description">Nama selalu ditampilkan & wajib.</p>
          </td>
        </tr>
        <tr>
          <th scope="row">Label Tombol</th>
          <td><input type="text" name="ubp_form_button" value="<?php echo esc_attr($btn_label); ?>" style="width:360px"></td>
        </tr>
      </table>

      <p><button type="submit" name="ubp_aff_save" class="button button-primary">Save</button></p>
    </form>
  </div>
  <?php
}


/* =========================================================
 * SECTION 14 — AFFILIATE INFO JSON (/ubp-affinfo?aff=ID)
 * ========================================================= */
add_action('init', function(){
  add_rewrite_rule('^ubp-affinfo/?$', 'index.php?ubp_affinfo=1', 'top');
});
add_filter('query_vars', function($qv){ $qv[]='ubp_affinfo'; $qv[]='aff'; return $qv; });

add_action('template_redirect', function(){
  if (!get_query_var('ubp_affinfo')) return;

  $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
  $allowed = [
    'https://bmtours.id',
    'https://www.bmtours.id',
  ];
  if ($origin && in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: '.$origin);
    header('Vary: Origin');
  }
  header('Access-Control-Allow-Methods: GET, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type');
  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { status_header(204); exit; }

  $aff = (int)get_query_var('aff');
  $user = get_userdata($aff);
  if (!$user){ status_header(404); echo 'Not found'; exit; }

  $data = [
    'id'         => $user->ID,
    'name'       => $user->display_name ?: $user->user_login,
    'avatar_url' => get_avatar_url($user->ID,['size'=>96]),
  ];

  header('Content-Type: application/json; charset=utf-8');
  echo wp_json_encode($data); exit;
});


/* =========================================================
 * SECTION X — Per-Link Intake Settings + Code Generator (CPT)
 * ========================================================= */
add_action('add_meta_boxes', function(){
  add_meta_box('ubp_intake_link_settings', 'Intake Settings (Per Link)', function($post){
    $after    = get_post_meta($post->ID, '_ubp_intake_after', true) ?: '';
    $group    = get_post_meta($post->ID, '_ubp_intake_group', true) ?: get_option('ubp_aff_group', UBP_WA_GROUP_URL);
    $redirect = get_post_meta($post->ID, '_ubp_intake_redirect', true) ?: get_option('ubp_aff_redirect', home_url('/terimakasih'));
    $hub      = get_post_meta($post->ID, '_ubp_intake_hub', true) ?: get_option('ubp_aff_hub', home_url('/continue'));
    $send_wa  = get_post_meta($post->ID, '_ubp_intake_send_wa', true);
    if ($send_wa === '') $send_wa = '1';

    $g_show_email = get_option('ubp_form_show_email','1');
    $g_show_wa    = get_option('ubp_form_show_wa','1');
    $g_btn_label  = get_option('ubp_form_button','Daftar');

    $show_email_link = get_post_meta($post->ID, '_ubp_form_show_email', true);
    $show_wa_link    = get_post_meta($post->ID, '_ubp_form_show_wa', true);
    $btn_link        = get_post_meta($post->ID, '_ubp_form_button', true);

    $show_email_val = $show_email_link === '' ? $g_show_email : $show_email_link;
    $show_wa_val    = $show_wa_link === '' ? $g_show_wa    : $show_wa_link;
    $btn_val        = $btn_link ?: $g_btn_label;
    ?>
    <table class="form-table">
      <tr>
        <th scope="row">Redirect (override)</th>
        <td>
          <select name="ubp_intake_after">
            <option value="">— gunakan default global —</option>
            <option value="group" <?php selected($after,'group'); ?>>Grup WhatsApp</option>
            <option value="url"   <?php selected($after,'url');   ?>>Custom URL</option>
            <option value="hub"   <?php selected($after,'hub');   ?>>Halaman Hub</option>
            <option value="none"  <?php selected($after,'none');  ?>>Tidak Redirect</option>
          </select>
          <p class="description">Jika kosong, pakai setting global di “Affiliate Settings”.</p>
        </td>
      </tr>
      <tr>
        <th scope="row">Link Grup (jika pilih Group)</th>
        <td><input type="url" name="ubp_intake_group" value="<?php echo esc_attr($group); ?>" style="width:100%"></td>
      </tr>
      <tr>
        <th scope="row">Redirect URL (jika pilih URL)</th>
        <td><input type="url" name="ubp_intake_redirect" value="<?php echo esc_attr($redirect); ?>" style="width:100%"></td>
      </tr>
      <tr>
        <th scope="row">Hub URL (jika pilih Hub)</th>
        <td><input type="url" name="ubp_intake_hub" value="<?php echo esc_attr($hub); ?>" style="width:100%"></td>
      </tr>
      <tr>
        <th scope="row">Kirim WA MagicLink?</th>
        <td>
          <label><input type="radio" name="ubp_intake_send_wa" value="1" <?php checked($send_wa,'1'); ?>> Ya</label>
          &nbsp; &nbsp;
          <label><input type="radio" name="ubp_intake_send_wa" value="0" <?php checked($send_wa,'0'); ?>> Tidak</label>
        </td>
      </tr>
      <tr>
        <th scope="row">Form Fields (override)</th>
        <td>
          <label><input type="checkbox" name="ubp_form_show_email" value="1" <?php checked($show_email_val,'1'); ?>> Tampilkan Email</label><br>
          <label><input type="checkbox" name="ubp_form_show_wa" value="1" <?php checked($show_wa_val,'1'); ?>> Tampilkan Nomor WA</label>
          <p class="description">Nama selalu tampil & wajib.</p>
        </td>
      </tr>
      <tr>
        <th scope="row">Label Tombol (override)</th>
        <td><input type="text" name="ubp_form_button" value="<?php echo esc_attr($btn_val); ?>" style="width:360px"></td>
      </tr>
    </table>
    <?php
  }, 'ubp_afflink', 'normal', 'high');

  add_meta_box('ubp_intake_code_gen', 'Generate External Form Code', function($post){
    $action  = home_url('/ubp-intake');
    $key     = defined('UBP_INTAKE_KEY') ? UBP_INTAKE_KEY : '';

    $after   = get_post_meta($post->ID, '_ubp_intake_after', true) ?: '';
    $group   = get_post_meta($post->ID, '_ubp_intake_group', true) ?: get_option('ubp_aff_group', UBP_WA_GROUP_URL);
    $redirect= get_post_meta($post->ID, '_ubp_intake_redirect', true) ?: get_option('ubp_aff_redirect', home_url('/terimakasih'));
    $hub     = get_post_meta($post->ID, '_ubp_intake_hub', true) ?: get_option('ubp_aff_hub', home_url('/continue'));
    $send_wa = get_post_meta($post->ID, '_ubp_intake_send_wa', true);
    if ($send_wa === '') $send_wa = '1';

    $g_show_email = get_option('ubp_form_show_email','1');
    $g_show_wa    = get_option('ubp_form_show_wa','1');
    $g_btn_label  = get_option('ubp_form_button','Daftar');

    $show_email = get_post_meta($post->ID, '_ubp_form_show_email', true);
    $show_wa    = get_post_meta($post->ID, '_ubp_form_show_wa', true);
    $btn_label  = get_post_meta($post->ID, '_ubp_form_button', true);

    if ($show_email === '') $show_email = $g_show_email;
    if ($show_wa === '')    $show_wa    = $g_show_wa;
    if (!$btn_label)        $btn_label  = $g_btn_label;

    $afterHidden = $after !== '' ? '  <input type="hidden" name="after" value="'.esc_attr($after).'">'."\n" : '';

    $fields_html = [];
    $fields_html[] = '  <label>Nama*</label>
  <input type="text" name="nama" required style="width:100%;padding:12px;margin:8px 0;border-radius:10px;border:1px solid #e5e7eb;">';

    if ($show_email === '1') {
      $fields_html[] = '  <label>Email</label>
  <input type="email" name="email" style="width:100%;padding:12px;margin:8px 0;border-radius:10px;border:1px solid #e5e7eb;">';
    }
    if ($show_wa === '1') {
      $fields_html[] = '  <label>Nomor WA</label>
  <input type="tel" name="wa" placeholder="08xxxxxxxxxx" style="width:100%;padding:12px;margin:8px 0;border-radius:10px;border:1px solid #e5e7eb;">';
    }
    $fields_html = implode("\n  ", $fields_html);

    $code = <<<HTML
<form method="post" action="{$action}" style="max-width:480px;margin:auto;">
  <input type="hidden" name="key" value="{$key}">
  <input type="hidden" name="link_id" value="{$post->ID}">
  <input type="hidden" name="aff_digital" id="aff_digital">
  <input type="hidden" name="pid_digital" id="pid_digital">

{$afterHidden}  <input type="hidden" name="group" value="{$group}">
  <input type="hidden" name="redirect" value="{$redirect}">
  <input type="hidden" name="hub" value="{$hub}">
  <input type="hidden" name="send_wa" value="{$send_wa}">

  {$fields_html}

  <button type="submit" style="width:100%;padding:14px;border:0;border-radius:12px;background:#3b82f6;color:#fff;font-weight:700;">
    {$btn_label}
  </button>

  <p style="font-size:12px;color:#6b7280;margin-top:8px">
    Dengan menekan tombol, Anda menyetujui kami menghubungi via WhatsApp untuk mengirim tautan dan info lanjutan.
  </p>
</form>

<div id="affInfoCard" style="margin-top:12px;"></div>
<script>
(function(){
  const qs = new URLSearchParams(location.search);
  const aff = qs.get('aff_digital');
  if (!aff) return;
  var ifr = document.createElement('iframe');
  ifr.src = 'https://member.bmtours.id/ubp-affcard?aff=' + encodeURIComponent(aff);
  ifr.style.width = '100%';
  ifr.style.maxWidth = '420px';
  ifr.style.height = '100px';
  ifr.style.border = '0';
  ifr.style.borderRadius = '12px';
  ifr.style.overflow = 'hidden';
  document.getElementById('affInfoCard').appendChild(ifr);
})();
</script>

<script>
(function(){
  const qs = new URLSearchParams(location.search);
  const aff = qs.get('aff_digital') || '';
  const pid = qs.get('pid_digital') || '';
  var a = document.getElementById('aff_digital');
  var p = document.getElementById('pid_digital');
  if (a) a.value = aff;
  if (p) p.value = pid;
})();
</script>
HTML;

    ?>
    <p>Salin kode ini dan tempel di LP domain seberang. Kode sudah membawa <code>link_id</code> agar setting per-link dipakai.</p>
    <textarea id="ubp_ext_code" style="width:100%;height:420px;font-family:monospace;"><?php echo esc_textarea($code); ?></textarea>
    <p><button class="button button-primary" type="button" id="ubp_copy_code">Copy</button></p>
    <script>
      document.getElementById('ubp_copy_code').addEventListener('click', async ()=>{
        const ta = document.getElementById('ubp_ext_code');
        ta.select(); ta.setSelectionRange(0, 99999);
        try { await navigator.clipboard.writeText(ta.value); } catch(e){}
        alert('Code copied!');
      });
    </script>
    <?php
  }, 'ubp_afflink', 'normal', 'default');
});

// Simpan meta per-link
add_action('save_post_ubp_afflink', function($post_id){
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
  if (!current_user_can('edit_post', $post_id)) return;

  if (isset($_POST['ubp_intake_after']))    update_post_meta($post_id, '_ubp_intake_after', sanitize_text_field($_POST['ubp_intake_after']));
  if (isset($_POST['ubp_intake_group']))    update_post_meta($post_id, '_ubp_intake_group', esc_url_raw($_POST['ubp_intake_group']));
  if (isset($_POST['ubp_intake_redirect'])) update_post_meta($post_id, '_ubp_intake_redirect', esc_url_raw($_POST['ubp_intake_redirect']));
  if (isset($_POST['ubp_intake_hub']))      update_post_meta($post_id, '_ubp_intake_hub', esc_url_raw($_POST['ubp_intake_hub']));
  if (isset($_POST['ubp_intake_send_wa']))  update_post_meta($post_id, '_ubp_intake_send_wa', $_POST['ubp_intake_send_wa']=='0'?'0':'1');

  if (isset($_POST['ubp_form_show_email'])) update_post_meta($post_id, '_ubp_form_show_email', '1'); else update_post_meta($post_id, '_ubp_form_show_email', '0');
  if (isset($_POST['ubp_form_show_wa']))    update_post_meta($post_id, '_ubp_form_show_wa', '1');    else update_post_meta($post_id, '_ubp_form_show_wa', '0');
  if (isset($_POST['ubp_form_button']))     update_post_meta($post_id, '_ubp_form_button', sanitize_text_field($_POST['ubp_form_button']));
}, 10, 1);


/* =========================================================
 * SECTION 15 — AFFILIATE CARD WIDGET (/ubp-affcard?aff=ID)
 * ========================================================= */
add_action('init', function(){
  add_rewrite_rule('^ubp-affcard/?$', 'index.php?ubp_affcard=1', 'top');
});
add_filter('query_vars', function($qv){ $qv[]='ubp_affcard'; $qv[]='aff'; return $qv; });

add_action('template_redirect', function(){
  if (!get_query_var('ubp_affcard')) return;

  $aff = (int)get_query_var('aff');
  $user = get_userdata($aff);
  if (!$user){ status_header(404); echo 'Not found'; exit; }

  $name = $user->display_name ?: $user->user_login;
  $ava  = get_avatar_url($user->ID, ['size'=>96]);
  if (!$ava) $ava = 'https://www.gravatar.com/avatar/?d=mp&s=96';

  header('Content-Type: text/html; charset=utf-8');
  ?>
  <!doctype html>
  <html><head>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
      body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial;}
      .card{display:flex;gap:12px;align-items:center;padding:12px;border:1px solid #e5e7eb;border-radius:12px;background:#f9fafb;}
      .ava{width:52px;height:52px;border-radius:50%;border:1px solid #ddd;object-fit:cover}
      .label{font-size:12px;color:#6b7280}
      .name{font-size:16px;font-weight:700;color:#111827;line-height:1.15}
      .sub{font-size:12px;color:#6b7280}
    </style>
  </head><body>
    <div class="card">
      <img class="ava" src="<?php echo esc_url($ava); ?>" alt="Affiliate">
      <div>
        <div class="label">Yang Memberikan Informasi Kepada Anda</div>
        <div class="name"><?php echo esc_html($name); ?></div>
        <div class="sub">Siap membantu Anda hingga tuntas</div>
      </div>
    </div>
  </body></html>
  <?php
  exit;
});


/* =========================================================
 * SECTION 16 — AJAX: Prospek Fragment (List + Pager)
 * ========================================================= */
add_action('wp_ajax_ubp_list_prospek','ubp_ajax_list_prospek');

if (!function_exists('ubp_ajax_list_prospek')) {
  function ubp_ajax_list_prospek() {
    if (!is_user_logged_in()) {
      status_header(401);
      echo '<div class="ubp-card">Unauthorized</div>';
      wp_die();
    }

    $uid = get_current_user_id();
    global $wpdb;
    $tP = $wpdb->prefix . 'ubp_prospek';

    $allowed_status = ['lead','group','registered','paid'];
    $f = isset($_REQUEST['f']) ? strtolower(sanitize_text_field($_REQUEST['f'])) : 'all';
    $f = in_array($f, $allowed_status, true) ? $f : 'all';
    $page = isset($_REQUEST['p']) ? max(1, (int)$_REQUEST['p']) : 1;

    $per_page = 10;
    $offset   = ($page - 1) * $per_page;

    $where = "affiliate_id=%d";
    $args  = [$uid];
    if ($f !== 'all') { $where .= " AND status=%s"; $args[] = $f; }

    $total_rows = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tP WHERE $where", $args));
    $args_page  = array_merge($args, [$per_page, $offset]);

    $prospek = $wpdb->get_results($wpdb->prepare("
      SELECT pid, nama, email, wa, status, COALESCE(ts_last, ts_first) AS tsu
      FROM $tP
      WHERE $where
      ORDER BY tsu DESC
      LIMIT %d OFFSET %d
    ", $args_page));

    $total_pages = max(1, (int)ceil($total_rows / $per_page));

    $mask_wa = function($wa){
      $wa = preg_replace('/\D+/', '', (string)$wa);
      if (strlen($wa) <= 4) return $wa;
      return str_repeat('•', max(0, strlen($wa)-4)) . substr($wa, -4);
    };
    $badge = function($st){
      $colors = [
        'click'      => '#9CA3AF',
        'lead'       => '#3B82F6',
        'group'      => '#F59E0B',
        'registered' => '#10B981',
        'paid'       => '#14B8A6',
      ];
      $label = ucfirst($st);
      $bg = $colors[$st] ?? '#6B7280';
      return "<span class='ubp-badge' style='background:$bg'>$label</span>";
    };

    nocache_headers();
    header('Content-Type: text/html; charset=' . get_option('blog_charset'));

    echo '<div id="ubpProspekList" class="ubp-stack">';
    if (!empty($prospek)) {
      foreach ($prospek as $p) {
        $wa_mask = $mask_wa($p->wa);
        $hub     = add_query_arg([ defined('UBP_PID_PARAM') ? UBP_PID_PARAM : 'pid' => $p->pid ], home_url('/continue'));
        $sapaan  = rawurlencode("Assalamualaikum, ini tautan pribadi Anda untuk melanjutkan: $hub");
        $wa_url  = $p->wa ? "https://wa.me/{$p->wa}?text={$sapaan}" : '#';
        $joined  = add_query_arg([ defined('UBP_PID_PARAM') ? UBP_PID_PARAM : 'pid' => $p->pid ], home_url('/joined'));

        echo '<div class="ubp-card ubp-prospect" data-pid="'.esc_attr($p->pid).'">
          <div class="ubp-row" style="justify-content:space-between">
            <div>
              <div style="font-weight:700;font-size:15px">'.esc_html($p->nama ?: '(Tanpa Nama)').'</div>
              <div class="meta">'.$badge($p->status).'
                <span style="margin-left:8px;color:#6b7280;">'.esc_html($p->tsu ? date_i18n('d M Y H:i', strtotime($p->tsu)) : '-').'</span>
              </div>
            </div>
            <button class="ubp-btn ghost toggle" type="button">Detail</button>
          </div>
          <div class="detail" style="display:none;padding-top:8px;border-top:1px dashed #e5e7eb;font-size:14px">
            <div style="display:grid;grid-template-columns:1fr;gap:6px;margin-bottom:8px;">
              <div><strong>Email:</strong> '.esc_html($p->email ?: '-').'</div>
              <div><strong>WA:</strong> '.esc_html($wa_mask ?: '-').'</div>
              <div><strong>PID:</strong> <code>'.esc_html($p->pid).'</code></div>
            </div>
            <div class="ubp-row">
              <button class="ubp-btn primary" data-copy="'.esc_attr($hub).'">Copy MagicLink</button>'.
              ($p->wa ? '<a class="ubp-btn ghost" href="'.esc_url($wa_url).'" target="_blank" rel="noopener">Chat WA</a>' : '').
              '<a class="ubp-btn ghost" href="'.esc_url($joined).'">Tandai Gabung</a>
            </div>
          </div>
        </div>';
      }
    } else {
      echo '<div class="ubp-card">Belum ada prospek untuk ditampilkan.</div>';
    }
    echo '</div>';

    if ($total_pages > 1) {
      $prev = max(1, $page-1);
      $next = min($total_pages, $page+1);

      echo '<div id="ubpProspekPager" class="ubp-row" style="justify-content:center;margin-top:8px">';
      echo '<a class="ubp-btn ghost ubp-pg" href="#" data-f="'.esc_attr($f).'" data-p="'.esc_attr($prev).'">‹ Prev</a>';
      echo '<div style="padding:6px 10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;font-size:12px;">';
      echo 'Page '.(int)$page.' / '.(int)$total_pages;
      echo '</div>';
      echo '<a class="ubp-btn ghost ubp-pg" href="#" data-f="'.esc_attr($f).'" data-p="'.esc_attr($next).'">Next ›</a>';
      echo '</div>';
    } else {
      echo '<div id="ubpProspekPager"></div>';
    }

    wp_die();
  }
}