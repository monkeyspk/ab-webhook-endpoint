<?php
/**
 * Gutschein Kaeufer-Bestaetigungs E-Mail Template
 *
 * Dynamischer Footer aus parkourone_footer Option.
 * Logo aus WordPress Custom Logo (Theme Customizer).
 *
 * Verfuegbare Variablen:
 *   $email_body - Der gerenderte E-Mail-Inhalt
 */

// Logo: WordPress Custom Logo, Fallback auf Site Name
$logo_html = '';
$custom_logo_id = get_theme_mod('custom_logo');
if ($custom_logo_id) {
    $logo_url = wp_get_attachment_image_url($custom_logo_id, 'medium');
    if ($logo_url) {
        $logo_html = '<img style="max-width: 300px; height: auto;" src="' . esc_url($logo_url) . '" alt="' . esc_attr(get_bloginfo('name')) . ' Logo" />';
    }
}
if (empty($logo_html)) {
    $logo_html = '<h1 style="font-size: 24px; color: #333; margin: 0;">' . esc_html(get_bloginfo('name')) . '</h1>';
}

// Footer-Daten aus Theme-Option
$footer = get_option('parkourone_footer', []);
$company_name    = $footer['company_name'] ?? get_bloginfo('name');
$company_address = $footer['company_address'] ?? '';
$phone           = $footer['phone'] ?? '';
$email_addr      = $footer['email'] ?? get_option('admin_email');
$site_url        = home_url();
$site_domain     = wp_parse_url($site_url, PHP_URL_HOST);

// Rechtliche Seiten per Slug aufloesen
$agb_page = get_page_by_path('agb');
$agb_url = $agb_page ? get_permalink($agb_page) : home_url('/agb');
$ds_page = get_page_by_path('datenschutz');
$ds_url = $ds_page ? get_permalink($ds_page) : get_privacy_policy_url();
if (empty($ds_url)) {
    $ds_url = home_url('/datenschutz');
}
?>
<!-- Logo -->
<div style="font-family: Arial, sans-serif; color: #333;">
<div style="text-align: center; margin-bottom: 20px;">

&nbsp;

<?php echo $logo_html; ?>

&nbsp;

</div>
<!-- Content Section -->
<div style="font-family: Arial, sans-serif; color: #333; padding: 20px; max-width: 600px; margin: 0 auto;">

<?php echo $email_body; ?>

</div>
<!-- Divider -->
<div style="border-top: 1px solid #ddd; margin: 20px 0;"></div>
<!-- Footer Section -->
<div style="font-family: Arial, sans-serif; color: #777; font-size: 12px; padding: 20px; max-width: 600px; margin: 0 auto; line-height: 1.6;">

<strong><?php echo esc_html($company_name); ?></strong><br>
<?php if (!empty($company_address)) : ?>
<?php echo nl2br(esc_html($company_address)); ?><br>
<?php endif; ?>
<a style="color: #0066cc; text-decoration: none;" href="<?php echo esc_url($site_url); ?>"><?php echo esc_html($site_domain); ?></a><br>
<br>
<?php if (!empty($phone)) : ?>
Office: <a style="color: #0066cc; text-decoration: none;" href="tel:<?php echo esc_attr(preg_replace('/[^+0-9]/', '', $phone)); ?>"><?php echo esc_html($phone); ?></a><br>
<?php endif; ?>
E-Mail: <a style="color: #0066cc; text-decoration: none;" href="mailto:<?php echo esc_attr($email_addr); ?>"><?php echo esc_html($email_addr); ?></a><br>
<br>
<a style="color: #0066cc; text-decoration: none;" href="<?php echo esc_url($agb_url); ?>">Allgemeine Gesch&auml;ftsbedingungen</a> | <a style="color: #0066cc; text-decoration: none;" href="<?php echo esc_url($ds_url); ?>">Datenschutzerkl&auml;rung</a>

</div>
</div>
