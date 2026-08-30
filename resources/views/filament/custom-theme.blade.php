{{-- FASRE Premium Theme & Design System Styles --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    :root {
        --font-display: 'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        --font-body: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }

    body {
        font-family: var(--font-body) !important;
        background-color: #f8fafc;
        background-image: 
            radial-gradient(at 0% 0%, rgba(30, 58, 138, 0.04) 0px, transparent 50%),
            radial-gradient(at 100% 100%, rgba(245, 197, 24, 0.03) 0px, transparent 50%);
        background-attachment: fixed;
    }

    .dark body {
        background-color: #0b1120;
        background-image: 
            radial-gradient(at 0% 0%, rgba(30, 58, 138, 0.15) 0px, transparent 50%),
            radial-gradient(at 100% 100%, rgba(245, 197, 24, 0.05) 0px, transparent 50%);
    }

    /* Display Typography */
    h1, h2, h3, .fi-header-heading, .fi-ta-header-title {
        font-family: var(--font-display) !important;
        letter-spacing: -0.02em;
    }

    /* Glassmorphic Topbar */
    .fi-topbar {
        backdrop-filter: blur(16px) !important;
        background-color: rgba(255, 255, 255, 0.85) !important;
        border-bottom: 1px solid rgba(226, 232, 240, 0.8) !important;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.02), 0 1px 2px -1px rgba(0, 0, 0, 0.02) !important;
    }

    .dark .fi-topbar {
        background-color: rgba(15, 23, 42, 0.85) !important;
        border-bottom: 1px solid rgba(51, 65, 85, 0.8) !important;
    }

    /* Modern Glassmorphic Sidebar */
    .fi-sidebar {
        background-color: rgba(255, 255, 255, 0.92) !important;
        backdrop-filter: blur(12px) !important;
        border-right: 1px solid rgba(226, 232, 240, 0.8) !important;
    }

    .dark .fi-sidebar {
        background-color: rgba(15, 23, 42, 0.95) !important;
        border-right: 1px solid rgba(30, 41, 59, 0.8) !important;
    }

    /* Sidebar Navigation Active State */
    .fi-sidebar-item-active > a, 
    .fi-sidebar-item-active > button {
        background: linear-gradient(135deg, rgba(30, 58, 138, 0.12) 0%, rgba(37, 99, 235, 0.08) 100%) !important;
        color: #1e3a8a !important;
        font-weight: 600 !important;
        border-left: 3px solid #f5c518 !important;
        border-radius: 0 0.5rem 0.5rem 0 !important;
    }

    .dark .fi-sidebar-item-active > a, 
    .dark .fi-sidebar-item-active > button {
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.2) 0%, rgba(30, 58, 138, 0.15) 100%) !important;
        color: #93c5fd !important;
        border-left: 3px solid #f5c518 !important;
    }

    /* Modern Card Polish */
    .fi-section, .fi-wi-card, .fi-ta-ctn {
        border-radius: 1rem !important;
        border: 1px solid rgba(226, 232, 240, 0.8) !important;
        background-color: rgba(255, 255, 255, 0.95) !important;
        box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.05) !important;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
    }

    .dark .fi-section, .dark .fi-wi-card, .dark .fi-ta-ctn {
        border: 1px solid rgba(30, 41, 59, 0.8) !important;
        background-color: rgba(15, 23, 42, 0.85) !important;
        box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.25) !important;
    }

    /* Stat Cards Hover Animation */
    .fi-wi-stats-overview-stat {
        border-radius: 0.875rem !important;
        border: 1px solid rgba(226, 232, 240, 0.8) !important;
        background: linear-gradient(180deg, #ffffff 0%, #fcfdfe 100%) !important;
        transition: all 0.25s ease !important;
        position: relative;
        overflow: hidden;
    }

    .fi-wi-stats-overview-stat::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #1e3a8a, #3b82f6, #f5c518);
        opacity: 0.8;
    }

    .fi-wi-stats-overview-stat:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px -5px rgba(30, 58, 138, 0.08) !important;
    }

    .dark .fi-wi-stats-overview-stat {
        background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%) !important;
        border: 1px solid rgba(51, 65, 85, 0.8) !important;
    }

    /* Tables Refinement */
    .fi-ta-table thead th {
        font-size: 0.75rem !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.05em !important;
        color: #64748b !important;
        background-color: #f8fafc !important;
        border-bottom: 1px solid #e2e8f0 !important;
    }

    .dark .fi-ta-table thead th {
        color: #94a3b8 !important;
        background-color: #0f172a !important;
        border-bottom: 1px solid #1e293b !important;
    }

    .fi-ta-row:hover {
        background-color: rgba(241, 245, 249, 0.6) !important;
    }

    .dark .fi-ta-row:hover {
        background-color: rgba(30, 41, 59, 0.5) !important;
    }

    /* Primary Buttons & Action Buttons */
    .fi-btn-color-primary {
        background: linear-gradient(135deg, #1e3a8a 0%, #172554 100%) !important;
        box-shadow: 0 2px 4px rgba(30, 58, 138, 0.25) !important;
        border: 1px solid rgba(255, 255, 255, 0.1) !important;
        transition: all 0.15s ease !important;
    }

    .fi-btn-color-primary:hover {
        background: linear-gradient(135deg, #2563eb 0%, #1e3a8a 100%) !important;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35) !important;
    }

    /* Refined Badges */
    .fi-badge {
        font-weight: 600 !important;
        letter-spacing: 0.02em !important;
        border-radius: 9999px !important;
        padding: 0.2rem 0.6rem !important;
    }

    /* Modern Custom Scrollbars */
    ::-webkit-scrollbar {
        width: 6px;
        height: 6px;
    }

    ::-webkit-scrollbar-track {
        background: transparent;
    }

    ::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 9999px;
    }

    ::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    .dark ::-webkit-scrollbar-thumb {
        background: #334155;
    }
</style>
