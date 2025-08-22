<?php
// Ensure session exists
if (!isset($_SESSION)) {
    session_start();
}

// Fallback values if session data is not available
$companyName = $_SESSION['COMP_NAME'] ?? 'Diamond Wine Shopee';
$financialYear = $_SESSION['FIN_YEAR_DISPLAY'] ?? '2024-25';
$username = $_SESSION['username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WineSoft Dashboard</title>
    <style>
        /* ========== CSS Variables ========== */
        :root {
            primary-color: #2B6CB0;       /* Dominant blue for headers and primary actions */
  --primary-hover: #4299E1;       /* Lighter blue for hover states */
  --secondary-color: #F6AD55;     /* Warm orange for accents and secondary actions */
  --background-color: #F7FAFC;    /* Very light gray/blue background */
  --text-color: #2D3748;         /* Dark gray for main text */
  --light-text: #718096;         /* Medium gray for secondary text */
  --error-color: #E53E3E;        /* Red for errors/warnings */
  --success-color: #38A169;      /* Green for success states */
  --white: #FFFFFF;              /* Pure white */
  --border-radius: 6px;          /* Moderate rounded corners */
  --box-shadow: 0 1px 3px rgba(0,0,0,0.1); /* Subtle shadow */
  --transition: all 0.2s ease;   /* Smooth transitions */
 }

        /* ========== Dashboard Header Styles ========== */
        .dashboard-header {
            background: var(--white);
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            box-shadow: var(--box-shadow);
            display: flex;
            flex-direction: column;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 0.75rem;
            margin-bottom: 0.75rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .header-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header-brand h1 {
            font-size: 1.5rem;
            color: var(--primary-color);
            margin: 0;
            font-weight: 600;
        }

        .logo {
            font-size: 1.8rem;
            color: var(--secondary-color);
        }

        .header-actions .logout-btn {
            background-color: var(--primary-color);
            color: var(--white);
            padding: 0.5rem 1.25rem;
            border-radius: 20px;
            text-decoration: none;
            font-size: 0.9rem;
            transition: var(--transition);
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .header-actions .logout-btn:hover {
            background-color: var(--primary-hover);
            transform: translateY(-1px);
        }

        .header-sub {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            color: var(--light-text);
        }

        .welcome-text {
            color: var(--primary-color);
            font-weight: 500;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .header-container {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .header-actions {
                width: 100%;
                display: flex;
                justify-content: flex-end;
            }
            
            .header-sub {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <header class="dashboard-header">
        <div class="header-container">
            <div class="header-brand">
                <span class="logo">🍷</span>
                <h1><?= htmlspecialchars($companyName) ?></h1>
            </div>
            <div class="header-actions">
                <a href="logout.php" class="logout-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                        <path fill-rule="evenodd" d="M10 12.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v2a.5.5 0 0 0 1 0v-2A1.5 1.5 0 0 0 9.5 2h-8A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-2a.5.5 0 0 0-1 0v2z"/>
                        <path fill-rule="evenodd" d="M15.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708.708L14.293 7.5H5.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708l3-3z"/>
                    </svg>
                    Logout
                </a>
            </div>
        </div>
        <div class="header-sub">
            <p>Dashboard • FY <?= htmlspecialchars($financialYear) ?></p>
            <span class="welcome-text">Welcome, <?= htmlspecialchars($username) ?></span>
        </div>
    </header>
</body>
</html>