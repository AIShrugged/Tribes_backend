<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email Address</title>
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
        .content {
            margin-bottom: 30px;
        }
        .content p {
            font-size: 16px;
            margin-bottom: 20px;
            color: #555555;
        }
        .button-container {
            text-align: center;
            margin: 40px 0;
        }
        .verify-button {
            display: inline-block;
            padding: 16px 36px;
            background-color: #3498db;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            transition: background-color 0.3s;
        }
        .verify-button:hover {
            background-color: #2980b9;
        }
        .expiry-notice {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 30px 0;
            border-radius: 4px;
        }
        .expiry-notice p {
            margin: 0;
            color: #856404;
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
            .verify-button {
                padding: 14px 28px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>Verify Your Email Address</h1>
        </div>

        <div class="content">
            <p>Hello {{ $userName }},</p>

            <p>Thank you for registering! To complete your registration, please verify your email address by clicking the button below:</p>

            <div class="button-container">
                <a href="{{ $verificationUrl }}" class="verify-button">Verify Email Address</a>
            </div>

            <div class="expiry-notice">
                <p><strong>Important:</strong> This verification link will expire in {{ $expiryMinutes }} minutes.</p>
            </div>

            <p>If you didn't create an account, you can safely ignore this email.</p>

            <p style="margin-top: 30px; font-size: 14px; color: #777777;">
                If the button above doesn't work, copy and paste this link into your browser:<br>
                <a href="{{ $verificationUrl }}" style="color: #3498db; word-break: break-all;">{{ $verificationUrl }}</a>
            </p>
        </div>

        <div class="footer">
            <p>This is an automated email, please do not reply.</p>
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
