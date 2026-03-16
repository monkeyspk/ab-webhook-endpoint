<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <title><?php echo wp_specialchars_decode(get_bloginfo('name')); ?></title>
</head>
<body style="background-color: #f7f7f7; padding: 0; margin: 0; font-family: Arial, sans-serif;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="padding: 20px;">
        <tr>
            <td align="center">
                <table border="0" cellpadding="0" cellspacing="0" width="600" style="background-color: #ffffff; border-radius: 3px; padding: 20px;">
                    <tr>
                        <td align="center">
                            <h1 style="color: #333333; font-size: 24px; margin-bottom: 30px;">
                                <?php echo esc_html($header_text); ?>
                            </h1>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <div style="color: #666666; font-size: 16px; line-height: 24px;">
                                <?php echo wpautop($email_body); ?>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
