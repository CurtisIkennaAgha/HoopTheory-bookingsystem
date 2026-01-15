Setting up email (PHPMailer)

1) Do NOT commit real credentials. Use environment variables as shown in `.env.example`.

2) For quick testing in PowerShell (process-local, not persistent):

```powershell
$env:MAIL_USERNAME='your@gmail.com'
$env:MAIL_PASSWORD='your_app_password'
$env:MAIL_FROM_ADDRESS='your@gmail.com'
$env:MAIL_FROM_NAME='Your Company'
$env:MAIL_TEST_TO='recipient@example.com'
php php/testEmail.php
```

3) If using Gmail:
- Enable 2FA for the account and create an App Password, then use that as `MAIL_PASSWORD`.
- Ensure your server allows outbound connections to the SMTP port (587 or 465).

4) Troubleshooting:
- Set `MAIL_DEBUG=2` to see verbose SMTP debug output.
- If TLS fails behind a proxy/firewall, set `MAIL_ALLOW_SELF_SIGNED=1` temporarily for testing.
- Ensure `extension=openssl` is enabled in your PHP configuration and restart your web server / PHP-FPM.

5) Next steps:
- For production, store secrets in a secure store or system environment variables and rotate credentials regularly.
