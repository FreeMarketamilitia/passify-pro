<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Your Google Wallet Ticket</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
        <h1 style="color: #0073aa;">Your Google Wallet Ticket</h1>
        <p>Hello {{first_name}},</p>
        <p>Thank you for your order #{{order_number}}! Your ticket for <strong>{{event_name}}</strong> on {{event_date}} is ready.</p>
        <p>Click the link below to add it to your Google Wallet:</p>
        <p style="text-align: center;">
            <a href="{{save_link}}" style="display: inline-block; padding: 10px 20px; background-color: #0073aa; color: #fff; text-decoration: none; border-radius: 5px;">Add to Google Wallet</a>
        </p>
        <p>If the button doesn’t work, copy and paste this URL into your browser:</p>
        <p><a href="{{save_link}}">{{save_link}}</a></p>
        <p>Enjoy your event!</p>
        <p>Best regards,<br>Your Passify Pro Team</p>
    </div>
</body>
</html>