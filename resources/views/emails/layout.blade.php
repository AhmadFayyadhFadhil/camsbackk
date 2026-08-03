<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>CAMS — PT Widatra Bhakti</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f6f9;
            margin: 0;
            padding: 0;
            color: #333333;
        }
        .container {
            max-width: 600px;
            margin: 30px auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
            border-top: 5px solid #005691; /* Otsuka Blue */
        }
        .header {
            background-color: #005691;
            padding: 20px;
            text-align: center;
            color: #ffffff;
        }
        .header h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 600;
            letter-spacing: 1px;
        }
        .header p {
            margin: 5px 0 0 0;
            font-size: 12px;
            opacity: 0.8;
        }
        .content {
            padding: 30px 25px;
            line-height: 1.6;
        }
        .content h2 {
            margin-top: 0;
            color: #005691;
            font-size: 18px;
        }
        .content p {
            margin: 0 0 15px 0;
            font-size: 14px;
        }
        .details-box {
            background-color: #f8fafc;
            border-left: 4px solid #005691;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .details-box p {
            margin: 5px 0;
            font-size: 13px;
        }
        .footer {
            background-color: #f8fafc;
            padding: 20px;
            text-align: center;
            font-size: 11px;
            color: #64748b;
            border-top: 1px solid #e2e8f0;
        }
        .footer p {
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>CAMS</h1>
            <p>Cleaning Activity Monitoring System — PT Widatra Bhakti</p>
        </div>
        <div class="content">
            @yield('content')
        </div>
        <div class="footer">
            <p>Email ini dikirim secara otomatis oleh sistem CAMS PT Widatra Bhakti (Otsuka Group).</p>
            <p>&copy; {{ date('Y') }} PT Widatra Bhakti. All Rights Reserved.</p>
        </div>
    </div>
</body>
</html>
