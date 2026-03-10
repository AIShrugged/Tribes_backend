<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Changed</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            padding: 40px 30px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #2c3e50;
            font-size: 28px;
            margin: 0 0 10px 0;
        }
        .content p {
            font-size: 16px;
            margin-bottom: 20px;
            color: #555555;
        }
        .alert {
            background-color: #fdecea;
            border-left: 4px solid #e74c3c;
            padding: 15px;
            margin: 30px 0;
            border-radius: 4px;
        }
        .alert p {
            margin: 0;
            color: #922b21;
            font-size: 14px;
        }
        .footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
            font-size: 12px;
            color: #999999;
        }
        @media only screen and (max-width: 600px) {
            .email-container {
                padding: 20px 15px;
            }
            .header h1 {
                font-size: 24px;
            }
            .content p {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>Password Changed</h1>
        </div>

        <div class="content">
            <p>Hello {{ $userName }},</p>

            <p>Your password for your {{ config('app.name') }} account has been successfully changed.</p>

            <div class="alert">
                <p><strong>Didn't make this change?</strong> If you did not change your password, please contact our support team immediately, as your account may be compromised.</p>
            </div>

            <p>For security, all existing sessions remain active. If you suspect unauthorized access, you can log out from all devices by logging in and revoking your sessions.</p>
        </div>

        <div class="footer">
            <p>This is an automated email, please do not reply.</p>
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
