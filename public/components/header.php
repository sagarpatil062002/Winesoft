<?php
// Ensure session exists
if(!isset($_SESSION)) session_start();
?>
<header class="dashboard-header">
    <div class="header-container">
        <div class="header-brand">
            <span class="logo">🍷</span>
            <h1><?= $_SESSION['COMP_NAME'] ?? 'Diamond Wine Shopee' ?></h1>
        </div>
        <div class="header-actions">
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>
    <div class="header-sub">
        <p>Admin Dashboard • FY <?= $_SESSION['FIN_YEAR'] ?? '2024-25' ?></p>
        <span class="welcome-text">Welcome, <?= $_SESSION['user'] ?? 'Admin' ?></span>
    </div>
</header>

<style>
/* ========== Dashboard Header Styles ========== */
.dashboard-header {
    background: var(--white);
    padding: 1rem 1.5rem;
    border-radius: var(--border-radius);
    margin-bottom: 1.5rem;
    box-shadow: var(--box-shadow);
    display: flex;
    flex-direction: column;
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
        justify-content: flex-end;
    }
    
    .header-sub {
        flex-direction: column;
        gap: 0.5rem;
    }
}
</style>