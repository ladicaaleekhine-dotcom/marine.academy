<?php
/**
 * SMTP Mail Configuration Template
 *
 * Copy this file to config/mailer.php and set your real credentials.
 */

return [
    'host'         => getenv('MAIL_HOST')         ?: 'smtp.gmail.com',
    'port'         => (int)(getenv('MAIL_PORT')   ?: 587),
    'username'     => getenv('MAIL_USERNAME')     ?: 'your_email@gmail.com',
    'password'     => getenv('MAIL_PASSWORD')     ?: 'your_app_password_here',
    'encryption'   => getenv('MAIL_ENCRYPTION')   ?: 'tls',
    'from_address' => getenv('MAIL_FROM_ADDRESS') ?: 'your_email@gmail.com',
    'from_name'    => getenv('MAIL_FROM_NAME')    ?: 'NCST Maritime Academy',
];
