<?php
/**
 * TP-HR Header Template - Modern Design
 */
$current_user = Auth::user();
$isHR = hr_can_access_hr_dashboard();
$isCEO = isCEOOrAbove();
$canApproveOutsideAttendance = Auth::hasRole(MANAGER_ROLES);
$current_page = $current_page ?? '';
$cp_shell = $current_page;
$tp_hr_is_hr_route = is_string($cp_shell) && strncmp($cp_shell, 'hr-', 3) === 0;
$tp_hr_employee_tab_shell = !$tp_hr_is_hr_route;
$tp_hr_main_native_class = 'content-area tp-native-page';
if ($tp_hr_employee_tab_shell && ($cp_shell === 'dashboard')) {
    $tp_hr_main_native_class .= ' tp-native-page--home';
}
$appIconPath = '/assets/icons/icon-192-v3.png';
$appTouchIconPath = '/assets/icons/apple-touch-icon-v3.png';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#b79168">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="TP-HR">
    <meta name="csrf-token" content="<?php echo csrfToken(); ?>">
    <title><?php echo htmlspecialchars($page_title ?? 'TP-HR'); ?> - TP-HR</title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars($appIconPath); ?>">
    <link rel="shortcut icon" type="image/x-icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo htmlspecialchars($appTouchIconPath); ?>">

    <!-- PWA — service worker registration + iOS install hint -->
    <script src="/assets/js/pwa.js?v=2" defer></script>
    <script src="/assets/js/native-select.js?v=1" defer></script>

    <!-- Tailwind CSS (compiled) -->
    <link rel="stylesheet" href="/assets/css/app.css?v=31">
    <link rel="stylesheet" href="/assets/css/native-shell.css?v=31">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * {
            font-family: 'IBM Plex Sans Thai', sans-serif;
            -webkit-tap-highlight-color: transparent;
            box-sizing: border-box;
        }

        .touch-manipulation {
            touch-action: manipulation;
        }
        
        body {
            background: linear-gradient(135deg, #0f172a 0%, #2b2119 50%, #0f172a 100%);
            min-height: 100vh;
            min-height: 100dvh;
            padding-bottom: env(safe-area-inset-bottom, 0);
        }
        
        .sidebar {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.98) 0%, rgba(30, 41, 59, 0.98) 100%);
            border-right: 1px solid rgba(148, 163, 184, 0.1);
        }
        
        /* Sticky top bar — aligned with tp-checkin `.header-glass` */
        .header-glass {
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.15);
        }

        .glass-card {
            background: rgba(30, 41, 59, 0.72);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: none;
            box-shadow:
                0 10px 36px rgba(0, 0, 0, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.06);
            transition: box-shadow 0.25s ease, background-color 0.25s ease;
        }
        
        .glass-card:hover {
            box-shadow:
                0 14px 40px rgba(0, 0, 0, 0.34),
                inset 0 1px 0 rgba(255, 255, 255, 0.08);
        }
        
        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            min-height: 48px;
            border-radius: var(--tp-radius-small-control);
            color: rgba(148, 163, 184, 1);
            font-weight: 500;
            transition: all 0.2s ease;
            margin-bottom: 4px;
            touch-action: manipulation;
        }
        
        .nav-item:hover {
            background: rgba(199, 169, 137, 0.1);
            color: #fff;
        }
        
        .nav-item.active {
            background: linear-gradient(135deg, #b79168 0%, #8c6d4d 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(183, 145, 104, 0.4);
        }
        
        .nav-item i {
            width: 20px;
            text-align: center;
            font-size: 1rem;
        }
        
        .stat-card {
            background: rgba(30, 41, 59, 0.72);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: none;
            border-radius: var(--tp-radius-card);
            padding: var(--tp-card-pad-mobile);
            box-shadow:
                0 10px 36px rgba(0, 0, 0, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.06);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow:
                0 16px 44px rgba(0, 0, 0, 0.36),
                inset 0 1px 0 rgba(255, 255, 255, 0.08);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .quick-action {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 116px;
            padding: 20px 16px;
            border-radius: var(--tp-radius-card);
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid rgba(148, 163, 184, 0.1);
            transition: all 0.3s ease;
            touch-action: manipulation;
        }
        
        .quick-action:hover {
            background: rgba(199, 169, 137, 0.15);
            border-color: rgba(199, 169, 137, 0.3);
            transform: translateY(-4px);
        }
        
        .quick-action-icon {
            width: 56px;
            height: 56px;
            border-radius: var(--tp-radius-small-control);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
            font-size: 1.5rem;
        }
        
        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 58px;
            padding: 12px 20px;
            background: linear-gradient(135deg, #b79168 0%, #8c6d4d 100%);
            color: #fff;
            border-radius: var(--tp-radius-button);
            font-weight: 500;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(183, 145, 104, 0.3);
            touch-action: manipulation;
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(183, 145, 104, 0.4);
        }

        /* Hero primary CTA on dashboard — reads clearly as main action */
        .btn-primary-prominent {
            min-height: 58px;
            padding: 14px 28px;
            font-size: 1.0625rem;
            font-weight: 600;
            border-radius: clamp(18px, 3vw, 22px);
            box-shadow: 0 8px 32px rgba(183, 145, 104, 0.45), 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .btn-primary-prominent:hover {
            box-shadow: 0 12px 36px rgba(183, 145, 104, 0.5), 0 2px 8px rgba(0, 0, 0, 0.2);
        }
        
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 54px;
            padding: 10px 20px;
            background: rgba(148, 163, 184, 0.1);
            color: #fff;
            border-radius: var(--tp-radius-button);
            font-weight: 500;
            border: 1px solid rgba(148, 163, 184, 0.2);
            transition: all 0.2s ease;
            touch-action: manipulation;
        }
        
        .btn-secondary:hover {
            background: rgba(148, 163, 184, 0.2);
        }
        
        .input-field {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            padding: 12px 16px;
            min-height: 56px;
            font-size: 16px;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: var(--tp-radius-button);
            color: #fff;
            transition: all 0.2s ease;
            touch-action: manipulation;
        }

        /* type=date/time: intrinsic width ใหญ่ — ต้องห่อด้วย .input-date-shell และย่อด้วย flex */
        input.input-field[type="time"],
        input.input-field[type="datetime-local"] {
            max-width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }

        .input-date-shell {
            display: flex;
            align-items: stretch;
            width: 100%;
            max-width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }

        .input-date-shell input.input-field[type="date"] {
            flex: 1 1 0%;
            width: auto; /* ทับ width:100% ของ .input-field ให้ flex ย่อตามกรอบได้ */
            min-width: 0;
            max-width: 100%;
            box-sizing: border-box;
        }

        /* WebKit: ลด padding ภายใน datetime-edit ให้ไม่ดันเกินกรอบ */
        .input-date-shell input.input-field[type="date"]::-webkit-datetime-edit,
        .input-date-shell input.input-field[type="date"]::-webkit-datetime-edit-fields-wrapper {
            padding: 0;
        }
        .input-date-shell input.input-field[type="date"]::-webkit-calendar-picker-indicator {
            margin: 0;
            padding: 0;
            cursor: pointer;
        }
        
        .input-field:focus {
            outline: none;
            border-color: #b79168;
            box-shadow: 0 0 0 3px rgba(183, 145, 104, 0.2);
        }
        
        .input-field::placeholder {
            color: rgba(148, 163, 184, 0.5);
        }

        /* Native-style <select>: room for dropdown chevron (Safari / Chrome) */
        select.input-field {
            -webkit-appearance: none;
            appearance: none;
            padding-right: 2.75rem;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23d8c4ad'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.85rem center;
            background-size: 1.1rem 1.1rem;
        }

        /* แถบบนมือถือ — โทนเอกสาร / องค์กร (คงโครงสร้างเดิม) */
        .mobile-app-header.header-glass {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.94) 0%, rgba(15, 23, 42, 0.82) 100%);
            backdrop-filter: blur(32px) saturate(1.38);
            -webkit-backdrop-filter: blur(32px) saturate(1.38);
            border-bottom: 1px solid rgba(148, 163, 184, 0.16);
            box-shadow:
                0 1px 0 rgba(255, 255, 255, 0.06) inset,
                0 14px 44px rgba(0, 0, 0, 0.26);
        }

        .mobile-app-header-bar {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 0;
            padding: calc(env(safe-area-inset-top, 0px) + 12px) 1.125rem 16px;
            min-height: calc(env(safe-area-inset-top, 0px) + 72px);
        }
        /* ปุ่มเมนู + wordmark ชิดกันและชิดซ้าย */
        .mobile-nav-cluster {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            flex: 1 1 auto;
        }
        .mobile-app-header-bar .mobile-nav-brand {
            flex: 0 1 auto;
            display: inline-flex;
            align-items: center;
            justify-content: flex-start;
            min-width: 0;
            max-width: none;
            text-decoration: none;
            padding: 4px 0;
            /* measured 47px — one pixel under the UI_RULES tap minimum */
            min-height: var(--tp-native-touch-min, 48px);
            border-radius: 12px;
            text-shadow: 0 1px 4px rgba(0, 0, 0, 0.35);
        }
        .mobile-nav-brand:focus-visible {
            outline: 2px solid rgba(216, 196, 173, 0.65);
            outline-offset: 3px;
        }
        .mobile-nav-brand-stack {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: center;
            min-width: 0;
            line-height: 1.05;
        }
        .mobile-nav-brand-line1 {
            font-size: 1.5rem;
            font-weight: 900;
            letter-spacing: 0.04em;
            white-space: nowrap;
            line-height: 1;
        }
        .mobile-nav-brand-tp {
            color: #f8fafc;
            font-weight: 900;
        }
        .mobile-nav-brand-hr {
            color: #ef4444;
            font-weight: 900;
            text-shadow: 0 0 20px rgba(239, 68, 68, 0.35);
        }
        .mobile-nav-brand-tagline {
            display: block;
            margin-top: 4px;
            font-size: 0.625rem;
            font-weight: 600;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: rgba(148, 163, 184, 0.88);
        }

        /* Desktop / drawer — ชื่อระบบเดียวกับแถบมือถือ */
        .app-brand-title-row {
            font-size: 1.25rem;
            font-weight: 900;
            letter-spacing: 0.05em;
            line-height: 1.15;
            color: #f8fafc;
            margin: 0;
        }
        .app-brand-title-row .app-brand-tp {
            color: #f8fafc;
            font-weight: 900;
        }
        .app-brand-title-row .app-brand-hr {
            color: #ef4444;
            font-weight: 900;
        }
        .app-brand-tagline {
            margin: 4px 0 0 0;
            font-size: 0.625rem;
            font-weight: 600;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: rgba(100, 116, 139, 0.95);
        }
        .app-brand-title-row--compact {
            font-size: 1.25rem;
            font-weight: 900;
            letter-spacing: 0.045em;
        }
        .app-brand-tagline--compact {
            font-size: 0.5625rem;
            margin-top: 2px;
            letter-spacing: 0.15em;
        }
        .mobile-nav-menu-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 0 18px;
            border-radius: var(--tp-radius-small-control);
            border: none;
            background: rgba(255, 255, 255, 0.1);
            color: #f1f5f9;
            font-size: 0.8125rem;
            font-weight: 500;
            letter-spacing: 0.08em;
            box-shadow: none;
            transition: background 0.2s ease, color 0.2s ease;
        }
        .mobile-nav-menu-btn:hover {
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
        }
        .mobile-nav-menu-btn:active {
            transform: scale(0.98);
        }
        .mobile-nav-menu-btn:focus-visible {
            outline: 2px solid rgba(216, 196, 173, 0.65);
            outline-offset: 2px;
        }

        /* เมนูมือถือเต็มจอ — ไทล์กริด (ไม่ใช้ utility แบบ arbitrary) */
        .mobile-menu-overlay {
            position: fixed;
            inset: 0;
            z-index: 60;
            display: flex;
            flex-direction: column;
            min-height: 100dvh;
            background: linear-gradient(165deg, #0f172a 0%, #2b2119 42%, #0f172a 100%);
            -webkit-overflow-scrolling: touch;
        }
        .mobile-menu-overlay.hidden {
            display: none;
        }
        .mobile-menu-sheet {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            padding-left: max(1.125rem, env(safe-area-inset-left, 0px));
            padding-right: max(1.125rem, env(safe-area-inset-right, 0px));
            padding-bottom: max(1rem, env(safe-area-inset-bottom, 0px));
        }
        .mobile-menu-header {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-top: max(0.5rem, env(safe-area-inset-top, 0px));
            padding-bottom: 14px;
            margin-bottom: 4px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.14);
        }
        .mobile-menu-close {
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: var(--tp-radius-small-control);
            border: 1px solid rgba(148, 163, 184, 0.18);
            background: rgba(30, 41, 59, 0.65);
            color: #f1f5f9;
            cursor: pointer;
            touch-action: manipulation;
            transition: background 0.2s ease, border-color 0.2s ease;
        }
        .mobile-menu-close:hover {
            background: rgba(51, 65, 85, 0.85);
            border-color: rgba(216, 196, 173, 0.35);
        }
        .mobile-menu-close:focus-visible {
            outline: 2px solid rgba(216, 196, 173, 0.65);
            outline-offset: 2px;
        }
        .mobile-menu-scroll {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            overscroll-behavior: contain;
            padding-top: 12px;
            padding-bottom: 1.25rem;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .mobile-menu-scroll::-webkit-scrollbar {
            display: none;
            width: 0;
            height: 0;
        }
        .mobile-menu-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        /* Odd tile count leaves an empty hole; span last orphan full-width (employee grid + HR admin grid only). */
        .mobile-menu-scroll .mobile-menu-grid > a.mobile-menu-tile:last-child:nth-child(odd) {
            grid-column: 1 / -1;
        }
        .mobile-menu-context-hint {
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.06em;
            color: rgba(148, 163, 184, 0.95);
            margin: -4px 0 14px 0;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
            line-height: 1.35;
        }
        .mobile-menu-context-hint span.page-title {
            color: rgba(241, 245, 249, 0.98);
            font-weight: 600;
        }
        .mobile-menu-section-label {
            font-size: 0.6875rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: rgba(148, 163, 184, 0.9);
            margin: 1.35rem 0 10px 0;
            padding-top: 1rem;
            border-top: 1px solid rgba(148, 163, 184, 0.12);
        }
        /* HR block: พับได้ — ไม่ให้เห็นกริด HR เต็มจอเมื่อเปิดจากหน้าพนักงาน (ลงเวลา/ลา ...) */
        .mobile-menu-hr-details {
            margin-top: 1rem;
        }
        .mobile-menu-hr-summary {
            list-style: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            cursor: pointer;
            touch-action: manipulation;
            padding: 12px 10px;
            margin: 0 0 10px 0;
            border-radius: 14px;
            border: 1px solid rgba(148, 163, 184, 0.16);
            background: rgba(30, 41, 59, 0.4);
            color: rgba(226, 232, 240, 0.95);
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            -webkit-tap-highlight-color: transparent;
        }
        .mobile-menu-hr-summary::-webkit-details-marker {
            display: none;
        }
        .mobile-menu-hr-summary .mobile-menu-hr-chevron {
            font-size: 0.72rem;
            color: rgba(165, 180, 252, 0.9);
            transition: transform 0.2s ease;
        }
        .mobile-menu-hr-details[open] .mobile-menu-hr-chevron {
            transform: rotate(180deg);
        }
        .mobile-menu-tile {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            gap: 10px;
            min-height: 108px;
            padding: 14px 8px;
            border-radius: 16px;
            background: rgba(30, 41, 59, 0.52);
            border: 1px solid rgba(148, 163, 184, 0.12);
            color: #e2e8f0;
            font-size: 0.8125rem;
            font-weight: 600;
            line-height: 1.25;
            text-decoration: none;
            transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease, transform 0.15s ease;
            touch-action: manipulation;
            word-break: break-word;
        }
        .mobile-menu-tile:active {
            transform: scale(0.98);
        }
        .mobile-menu-tile i {
            font-size: 1.65rem;
            color: #a5b4fc;
        }
        .mobile-menu-tile:hover {
            background: rgba(199, 169, 137, 0.14);
            border-color: rgba(216, 196, 173, 0.28);
            color: #fff;
        }
        .mobile-menu-tile:hover i {
            color: #e8dccf;
        }
        .mobile-menu-tile.active {
            background: linear-gradient(145deg, #b79168 0%, #8c6d4d 100%);
            border-color: rgba(255, 255, 255, 0.12);
            color: #fff;
            box-shadow: 0 8px 24px rgba(183, 145, 104, 0.38);
        }
        .mobile-menu-tile.active i {
            color: #fff;
        }
        /* Full-width logout — อยู่นอกสองคอลัมน์กริดเพื่อกันพฤติกรรม display:contents ใน form */
        .mobile-menu-logout-wrap {
            margin-top: 2rem;
            padding-top: 1.35rem;
            padding-bottom: max(0.5rem, env(safe-area-inset-bottom, 0px));
            border-top: 1px solid rgba(148, 163, 184, 0.14);
            width: 100%;
            box-sizing: border-box;
        }
        .mobile-menu-logout-form {
            margin: 0;
            padding: 0;
            width: 100%;
            display: block;
        }
        .mobile-menu-logout-btn {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            min-height: 56px;
            padding: 14px 20px;
            border-radius: 20px;
            border: 1px solid rgba(248, 113, 113, 0.35);
            background: rgba(127, 29, 29, 0.26);
            color: #fecaca;
            font: inherit;
            font-size: 0.9375rem;
            font-weight: 650;
            letter-spacing: 0.03em;
            cursor: pointer;
            touch-action: manipulation;
            transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease, transform 0.12s ease;
        }
        .mobile-menu-logout-btn:hover {
            background: rgba(153, 27, 27, 0.34);
            border-color: rgba(252, 165, 165, 0.45);
            color: #fef2f2;
        }
        .mobile-menu-logout-btn:active {
            transform: scale(0.99);
        }
        .mobile-menu-logout-btn i {
            font-size: 1.125rem;
            color: #fca5a5;
            flex-shrink: 0;
        }
        /** @deprecated เคยผูกกับ tile ในกริด — คงชื่อว่าเลิกแล้ว ใช้ .mobile-menu-logout-btn */

        /* เมื่อเปิดเมนูเต็มจอ ซ่อนแท็บล่างแอปให้เหลือเพียงชั้นหนึ่ง (ไม่ชน UX ซ้อนโครเมียมด้านล่าง) */
        body.tp-hr-mobile-menu-open #tpHrMobileBottomTab:not(.always-visible) {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transform: translateY(100%);
        }
        @media (prefers-reduced-motion: reduce) {
            #tpHrMobileBottomTab {
                transition-duration: 0ms !important;
            }
            body.tp-hr-mobile-menu-open #tpHrMobileBottomTab:not(.always-visible) {
                transform: none !important;
            }
        }

        /* Dashboard hero — การ์ดเดียว แถวละ ไอคอน + ข้อความ (แนวนอนชัดเจน) */
        .dashboard-hero {
            margin-top: var(--tp-space-8);
            margin-bottom: var(--tp-section-gap-mobile);
        }
        .dashboard-hero h1.dashboard-hero-title {
            font-size: var(--tp-font-page-title);
            font-weight: 700;
            color: #fff;
            margin: 0 0 1rem 0;
            line-height: 1.25;
            letter-spacing: -0.02em;
        }
        @media (min-width: 640px) {
            .dashboard-hero h1.dashboard-hero-title {
                /* clamp in --tp-font-page-title covers responsive scale */
                line-height: 1.2;
            }
        }
        .dashboard-hero-summary {
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(30, 41, 59, 0.55);
            border-radius: var(--tp-ios-card-radius);
            padding: 0;
            overflow: hidden;
            margin-bottom: var(--tp-space-20);
        }
        .dashboard-hero-row {
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 14px 18px;
            min-height: 54px;
        }
        .dashboard-hero-row + .dashboard-hero-row {
            border-top: 1px solid rgba(255, 255, 255, 0.07);
        }
        .dashboard-hero-icon {
            flex-shrink: 0;
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(199, 169, 137, 0.2);
            color: #ddd6fe;
            font-size: 16px;
        }
        .dashboard-hero-text {
            flex: 1;
            min-width: 0;
            font-size: 0.9375rem;
            line-height: 1.5;
            color: #e2e8f0;
            word-break: break-word;
        }
        .dashboard-hero-cta .btn-primary-prominent {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        @media (min-width: 768px) {
            .dashboard-hero-inner {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 1.5rem;
            }
            .dashboard-hero-main {
                flex: 1;
                min-width: 0;
            }
            .dashboard-hero-summary {
                max-width: 26rem;
                margin-bottom: 0;
            }
            .dashboard-hero-cta {
                flex-shrink: 0;
                align-self: center;
            }
            .dashboard-hero-cta .btn-primary-prominent {
                width: auto;
                min-width: 13rem;
            }
        }
        
        /* โครงเนื้อหา: mobile-first — กันกรณี Tailwind ไม่มี xl: แต่ยังจอง margin ซ้ายแบบเดสก์ท็อป */
        .content-area {
            margin-left: 0;
            margin-right: 0;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            min-height: 100vh;
        }
        @media (max-width: 1279px) {
            /*
             * ความสูงใต้แถบหัวมือถือ — อย่างเดียวที่ต้องมาจาก inline (< main > อยู่ก่อน Tailwind CDN)
             * padding ซ้าย/ขวา/ล่าง + buffer scroll อยู่ที่ assets/css/native-shell.css — อย่ารีเซ็ตเป็น 0
             * เดิม body.tp-native-app .content-area.tp-native-page { padding:0 } ชนคัสเคดภายหลัง link แล้วทับ padding จาก native-shell หมด
             */
            main.content-area.tp-native-page {
                padding-top: calc(env(safe-area-inset-top, 0px) + 6.75rem);
            }
            .overflow-x-auto {
                overscroll-behavior-x: contain;
                -webkit-overflow-scrolling: touch;
            }
        }
        @media (min-width: 1280px) {
            /* margin-left + width:100% เดิม = ล้นขวา (ต้องหักความกว้าง sidebar) */
            .content-area {
                margin-left: 280px;
                width: calc(100% - 280px);
                max-width: calc(100% - 280px);
            }
        }

        /* Shell 1280px+ = sidebar; ต่ำกว่านั้น = แถบบน/ล่าง + เมนูเต็มจอ (ไม่พึ่ง xl: ใน app.css) */
        aside.app-sidebar-desktop {
            display: none;
        }
        @media (min-width: 1280px) {
            aside.app-sidebar-desktop {
                display: block;
            }
            .app-shell-mobile-only {
                display: none !important;
            }
            /* Toast: มุมขวาล่างบนเดสก์ท็อป (ไม่พึ่ง Tailwind xl:) */
            #toast:not(.hidden) {
                left: auto;
                right: 1rem;
                bottom: 1rem;
                max-width: min(28rem, calc(100vw - 280px - 2rem));
            }
            #toast:not(.hidden) .toast-panel {
                min-width: 300px;
                width: auto;
            }
        }

        @media (max-width: 640px) {
            .glass-card,
            .stat-card {
                border-radius: var(--tp-ios-card-radius, 24px);
            }

            .quick-action {
                padding: 16px 10px;
                min-height: 104px;
                border-radius: var(--tp-ios-card-radius, 24px);
            }

            .quick-action-icon {
                width: 48px;
                height: 48px;
                border-radius: var(--tp-radius-small-control, 14px);
                margin-bottom: 10px;
            }
        }
        
        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.125rem;
            font-weight: 600;
            color: #fff;
            margin-bottom: 16px;
        }
        
        .section-title i {
            font-size: 1rem;
        }
        
        /* Badge Styles */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .badge-success {
            background: rgba(16, 185, 129, 0.2);
            color: #34d399;
        }
        
        .badge-warning {
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
        }
        
        .badge-danger {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
        }
        
        .badge-info {
            background: rgba(59, 130, 246, 0.2);
            color: #60a5fa;
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.5);
        }
        
        ::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.3);
            border-radius: 3px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(148, 163, 184, 0.5);
        }
        
        /* Table Styles */
        .data-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .data-table th {
            background: rgba(30, 41, 59, 0.5);
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            color: rgba(148, 163, 184, 1);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .data-table th:first-child {
            border-radius: 12px 0 0 0;
        }
        
        .data-table th:last-child {
            border-radius: 0 12px 0 0;
        }
        
        .data-table td {
            padding: 16px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
            color: #fff;
        }
        
        .data-table tbody tr:hover {
            background: rgba(199, 169, 137, 0.05);
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-fade-in {
            animation: fadeInUp 0.3s ease forwards;
        }
    </style>
</head>
<body class="text-slate-300 tp-native-app<?php echo $tp_hr_employee_tab_shell ? ' tp-with-tab-nav' : ''; ?>">

<!-- Sidebar -->
<aside class="app-sidebar-desktop sidebar fixed left-0 top-0 w-[280px] h-screen overflow-y-auto z-50">
    <div class="p-6">
        <!-- Logo -->
        <a href="/" class="flex items-center gap-4 mb-8 group">
            <div class="w-12 h-12 rounded-2xl overflow-hidden shadow-lg shadow-primary-500/30 ring-1 ring-white/10 group-hover:scale-105 transition-transform flex-shrink-0">
                <img src="<?php echo htmlspecialchars($appIconPath); ?>" alt="" width="48" height="48" class="w-full h-full object-cover" decoding="async">
            </div>
            <div class="min-w-0">
                <h1 class="app-brand-title-row"><span class="app-brand-tp">TP-</span><span class="app-brand-hr">HR</span></h1>
                <p class="app-brand-tagline">Human Resources</p>
            </div>
        </a>
        
        <!-- User Info -->
        <div class="mb-6 p-5 rounded-2xl bg-slate-800/50 border border-slate-700/50">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-400 to-emerald-600 flex items-center justify-center text-white font-bold">
                    <?php echo mb_substr($current_user['first_name_th'] ?? $current_user['username'], 0, 1); ?>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-white font-medium truncate"><?php echo htmlspecialchars($current_user['first_name_th'] ?? $current_user['username']); ?></p>
                    <p class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($current_user['position'] ?? $current_user['role_name'] ?? 'พนักงาน'); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Main Navigation -->
        <nav class="space-y-1">
            <a href="/" class="nav-item <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i>
                <span>หน้าแรก</span>
            </a>
            
            <a href="/checkin.php" class="nav-item <?php echo $current_page === 'checkin' ? 'active' : ''; ?>">
                <i class="fas fa-fingerprint"></i>
                <span>ลงเวลาเข้า-ออก</span>
            </a>
            
            <a href="/leave.php" class="nav-item <?php echo $current_page === 'leave' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i>
                <span>การลา</span>
            </a>

            <a href="/holidays.php" class="nav-item <?php echo $current_page === 'holidays' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-day"></i>
                <span>วันหยุดประจำปี</span>
            </a>
            
            <a href="/payslip.php" class="nav-item <?php echo $current_page === 'payslip' ? 'active' : ''; ?>">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>สลิปเงินเดือน</span>
            </a>
            <a href="/employee_finance.php" class="nav-item <?php echo $current_page === 'employee-finance' ? 'active' : ''; ?>">
                <i class="fas fa-hand-holding-dollar"></i>
                <span>เงินกู้และเบิกล่วงหน้า</span>
            </a>
            
            <a href="/certificate.php" class="nav-item <?php echo $current_page === 'certificate' ? 'active' : ''; ?>">
                <i class="fas fa-file-signature"></i>
                <span>ขอใบรับรอง</span>
            </a>
            
            <a href="/dayoff_schedule.php" class="nav-item <?php echo $current_page === 'dayoff' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-week"></i>
                <span>วันหยุดประจำสัปดาห์</span>
            </a>

            <?php if ($canApproveOutsideAttendance && !$isHR): ?>
            <a href="/hr/outside_attendance.php" class="nav-item <?php echo $current_page === 'hr-outside-attendance' ? 'active' : ''; ?>">
                <i class="fas fa-location-dot"></i>
                <span>อนุมัตินอกสถานที่</span>
            </a>
            <?php endif; ?>
            
            <a href="/profile.php" class="nav-item <?php echo $current_page === 'profile' ? 'active' : ''; ?>">
                <i class="fas fa-user"></i>
                <span>ข้อมูลส่วนตัว</span>
            </a>
        </nav>
        
        <?php if ($isHR): ?>
        <!-- HR Admin Section -->
        <div class="mt-6 pt-6 border-t border-slate-700/50">
            <p class="text-xs text-slate-500 uppercase tracking-wider mb-3 px-2 font-semibold">HR Admin</p>
            
            <nav class="space-y-1">
                <a href="/hr/index.php" class="nav-item <?php echo $current_page === 'hr-dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-th-large"></i>
                    <span>แดชบอร์ด HR</span>
                </a>
                <a href="/hr/employees.php" class="nav-item <?php echo $current_page === 'hr-employees' ? 'active' : ''; ?>">
                    <i class="fas fa-users-cog"></i>
                    <span>จัดการพนักงาน</span>
                </a>
                <a href="/hr/employee_summaries.php" class="nav-item <?php echo $current_page === 'hr-employee-summaries' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i>
                    <span>สรุปรายพนักงาน</span>
                </a>
                
                <a href="/hr/attendance.php" class="nav-item <?php echo $current_page === 'hr-attendance' ? 'active' : ''; ?>">
                    <i class="fas fa-user-clock"></i>
                    <span>จัดการลงเวลา</span>
                </a>

                <a href="/hr/outside_attendance.php" class="nav-item <?php echo $current_page === 'hr-outside-attendance' ? 'active' : ''; ?>">
                    <i class="fas fa-location-dot"></i>
                    <span>อนุมัตินอกสถานที่</span>
                </a>
                
                <a href="/hr/leaves.php" class="nav-item <?php echo $current_page === 'hr-leaves' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-check"></i>
                    <span>อนุมัติการลา</span>
                </a>
                
                <?php if ($isCEO): ?>
                <a href="/hr/attendance_adjustments.php" class="nav-item <?php echo $current_page === 'hr-attendance-adjustments' ? 'active' : ''; ?>">
                    <i class="fas fa-business-time"></i>
                    <span>อนุมัติแก้เวลา</span>
                </a>

                <a href="/hr/dayoff_approvals.php" class="nav-item <?php echo $current_page === 'hr-dayoff' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-day"></i>
                    <span>อนุมัติเปลี่ยนวันหยุด</span>
                </a>

                <a href="/hr/holiday_work_approvals.php" class="nav-item <?php echo $current_page === 'hr-holiday-work' ? 'active' : ''; ?>">
                    <i class="fas fa-briefcase"></i>
                    <span>อนุมัติทำงานวันหยุด</span>
                </a>
                <?php endif; ?>
                
                <a href="/hr/documents.php" class="nav-item <?php echo $current_page === 'hr-documents' ? 'active' : ''; ?>">
                    <i class="fas fa-file-alt"></i>
                    <span>จัดการเอกสาร</span>
                </a>

                <a href="/hr/document_templates.php" class="nav-item <?php echo $current_page === 'hr-document-templates' ? 'active' : ''; ?>">
                    <i class="fas fa-file-signature"></i>
                    <span>ตั้งค่าเอกสารรับรอง</span>
                </a>
                
                <?php if ($isCEO): ?>
                <a href="/hr/reports.php" class="nav-item <?php echo $current_page === 'hr-reports' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i>
                    <span>รายงาน</span>
                </a>

                <a href="/hr/api_keys.php" class="nav-item <?php echo $current_page === 'hr-api-keys' ? 'active' : ''; ?>">
                    <i class="fas fa-key"></i>
                    <span>คีย์ API ภายนอก</span>
                </a>

                <a href="/hr/settings.php" class="nav-item <?php echo $current_page === 'hr-settings' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i>
                    <span>ตั้งค่าระบบ</span>
                </a>
                <?php endif; ?>
            </nav>
        </div>
        <?php endif; ?>
        
        <!-- Logout -->
        <div class="mt-6 pt-6 border-t border-slate-700/50">
            <form method="post" action="/logout.php" class="m-0">
                <?php echo csrfField(); ?>
                <button type="submit" class="nav-item text-red-400 hover:bg-red-500/10 hover:text-red-300 w-full text-left border-0 bg-transparent cursor-pointer font-[inherit] text-base whitespace-nowrap">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>ออกจากระบบ</span>
                </button>
            </form>
        </div>
    </div>
</aside>

<!-- Mobile header: เมนู + wordmark ชิดซ้าย (ไม่แสดงไอคอนในแถบ) -->
<header class="app-shell-mobile-only mobile-app-header header-glass fixed top-0 left-0 right-0 z-40">
    <div class="mobile-app-header-bar">
        <div class="mobile-nav-cluster">
            <button type="button" id="mobileMenuBtn" class="mobile-nav-menu-btn touch-manipulation whitespace-nowrap">
                เมนู
            </button>
            <a href="/" class="mobile-nav-brand touch-manipulation" aria-label="TP-HR Human Resources — หน้าแรก">
                <span class="mobile-nav-brand-stack">
                    <span class="mobile-nav-brand-line1" aria-hidden="true"><span class="mobile-nav-brand-tp">TP-</span><span class="mobile-nav-brand-hr">HR</span></span>
                    <span class="mobile-nav-brand-tagline">Human Resources</span>
                </span>
            </a>
        </div>
    </div>
</header>

<!-- Mobile menu: เต็มจอ + กริดไอคอน -->
<div id="mobileSidebar" class="app-shell-mobile-only mobile-menu-overlay hidden" role="dialog" aria-modal="true" aria-label="เมนูระบบ TP-HR">
    <div class="mobile-menu-sheet">
        <header class="mobile-menu-header">
            <a href="/" class="flex items-center gap-3 min-w-0 touch-manipulation">
                <div class="w-10 h-10 rounded-xl overflow-hidden ring-1 ring-white/10 flex-shrink-0 shadow-md">
                    <img src="<?php echo htmlspecialchars($appIconPath); ?>" alt="" width="40" height="40" class="w-full h-full object-cover" decoding="async">
                </div>
                <span class="min-w-0">
                    <span class="block app-brand-title-row app-brand-title-row--compact"><span class="app-brand-tp">TP-</span><span class="app-brand-hr">HR</span></span>
                    <span class="app-brand-tagline app-brand-tagline--compact block">Human Resources</span>
                </span>
            </a>
            <button type="button" class="mobile-menu-close" onclick="closeMobileMenu()" aria-label="ปิดเมนู">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </header>
        <div class="mobile-menu-scroll">
            <p class="mobile-menu-context-hint px-1" role="note">
                <strong class="font-semibold text-slate-300">หน้าปัจจุบัน:</strong>
                <?php echo htmlspecialchars($page_title ?? 'TP-HR'); ?>
                <span class="block mt-1.5 text-slate-400 font-normal tracking-normal text-[0.8rem]"><span class="normal-case">แผงเมนูนี้อยู่บนหน้าเดิม — <strong class="text-slate-200">ปิดเมนู (✕)</strong> เพื่อลงมือฟอร์มลงเวลา / ทำงานในเนื้อหาหลักได้</span></span>
            </p>
            <nav class="mobile-menu-grid" aria-label="เมนูหลัก">
                <a href="/" class="mobile-menu-tile <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-home" aria-hidden="true"></i>
                    <span>หน้าแรก</span>
                </a>
                <a href="/checkin.php" class="mobile-menu-tile <?php echo $current_page === 'checkin' ? 'active' : ''; ?>">
                    <i class="fas fa-fingerprint" aria-hidden="true"></i>
                    <span>ลงเวลาเข้า-ออก</span>
                </a>
                <a href="/leave.php" class="mobile-menu-tile <?php echo $current_page === 'leave' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                    <span>การลา</span>
                </a>
                <a href="/holidays.php" class="mobile-menu-tile <?php echo $current_page === 'holidays' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-day" aria-hidden="true"></i>
                    <span>วันหยุดประจำปี</span>
                </a>
                <a href="/payslip.php" class="mobile-menu-tile <?php echo $current_page === 'payslip' ? 'active' : ''; ?>">
                    <i class="fas fa-file-invoice-dollar" aria-hidden="true"></i>
                    <span>สลิปเงินเดือน</span>
                </a>
                <a href="/certificate.php" class="mobile-menu-tile <?php echo $current_page === 'certificate' ? 'active' : ''; ?>">
                    <i class="fas fa-file-signature" aria-hidden="true"></i>
                    <span>ขอใบรับรอง</span>
                </a>
                <a href="/dayoff_schedule.php" class="mobile-menu-tile <?php echo $current_page === 'dayoff' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-week" aria-hidden="true"></i>
                    <span>วันหยุดประจำสัปดาห์</span>
                </a>
                <?php if ($canApproveOutsideAttendance && !$isHR): ?>
                <a href="/hr/outside_attendance.php" class="mobile-menu-tile <?php echo $current_page === 'hr-outside-attendance' ? 'active' : ''; ?>">
                    <i class="fas fa-location-dot" aria-hidden="true"></i>
                    <span>อนุมัตินอกสถานที่</span>
                </a>
                <?php endif; ?>
                <a href="/profile.php" class="mobile-menu-tile <?php echo $current_page === 'profile' ? 'active' : ''; ?>">
                    <i class="fas fa-user" aria-hidden="true"></i>
                    <span>ข้อมูลส่วนตัว</span>
                </a>
            </nav>

            <?php if ($isHR): ?>
            <details class="mobile-menu-hr-details" <?php echo $tp_hr_is_hr_route ? 'open' : ''; ?>>
                <summary class="mobile-menu-hr-summary">
                    <span>เมนู HR Admin</span>
                    <i class="fas fa-chevron-down mobile-menu-hr-chevron" aria-hidden="true"></i>
                </summary>
                <nav class="mobile-menu-grid" aria-label="เมนูผู้ดูแล HR">
                    <a href="/hr/index.php" class="mobile-menu-tile <?php echo $current_page === 'hr-dashboard' ? 'active' : ''; ?>">
                        <i class="fas fa-th-large" aria-hidden="true"></i>
                        <span>แดชบอร์ด HR</span>
                    </a>
                    <a href="/hr/employees.php" class="mobile-menu-tile <?php echo $current_page === 'hr-employees' ? 'active' : ''; ?>">
                        <i class="fas fa-users-cog" aria-hidden="true"></i>
                        <span>จัดการพนักงาน</span>
                    </a>
                    <a href="/hr/employee_summaries.php" class="mobile-menu-tile <?php echo $current_page === 'hr-employee-summaries' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-bar" aria-hidden="true"></i>
                        <span>สรุปรายพนักงาน</span>
                    </a>
                    <a href="/hr/attendance.php" class="mobile-menu-tile <?php echo $current_page === 'hr-attendance' ? 'active' : ''; ?>">
                        <i class="fas fa-user-clock" aria-hidden="true"></i>
                        <span>จัดการลงเวลา</span>
                    </a>
                    <a href="/hr/outside_attendance.php" class="mobile-menu-tile <?php echo $current_page === 'hr-outside-attendance' ? 'active' : ''; ?>">
                        <i class="fas fa-location-dot" aria-hidden="true"></i>
                        <span>อนุมัตินอกสถานที่</span>
                    </a>
                    <a href="/hr/leaves.php" class="mobile-menu-tile <?php echo $current_page === 'hr-leaves' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-check" aria-hidden="true"></i>
                        <span>อนุมัติการลา</span>
                    </a>
                    <?php if ($isCEO): ?>
                    <a href="/hr/attendance_adjustments.php" class="mobile-menu-tile <?php echo $current_page === 'hr-attendance-adjustments' ? 'active' : ''; ?>">
                        <i class="fas fa-business-time" aria-hidden="true"></i>
                        <span>อนุมัติแก้เวลา</span>
                    </a>
                    <a href="/hr/dayoff_approvals.php" class="mobile-menu-tile <?php echo $current_page === 'hr-dayoff' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-day" aria-hidden="true"></i>
                        <span>อนุมัติเปลี่ยนวันหยุด</span>
                    </a>
                    <a href="/hr/holiday_work_approvals.php" class="mobile-menu-tile <?php echo $current_page === 'hr-holiday-work' ? 'active' : ''; ?>">
                        <i class="fas fa-briefcase" aria-hidden="true"></i>
                        <span>อนุมัติทำงานวันหยุด</span>
                    </a>
                    <?php endif; ?>
                    <a href="/hr/documents.php" class="mobile-menu-tile <?php echo $current_page === 'hr-documents' ? 'active' : ''; ?>">
                        <i class="fas fa-file-alt" aria-hidden="true"></i>
                        <span>จัดการเอกสาร</span>
                    </a>
                    <a href="/hr/document_templates.php" class="mobile-menu-tile <?php echo $current_page === 'hr-document-templates' ? 'active' : ''; ?>">
                        <i class="fas fa-file-signature" aria-hidden="true"></i>
                        <span>ตั้งค่าเอกสารรับรอง</span>
                    </a>
                    <?php if ($isCEO): ?>
                    <a href="/hr/reports.php" class="mobile-menu-tile <?php echo $current_page === 'hr-reports' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-bar" aria-hidden="true"></i>
                        <span>รายงาน</span>
                    </a>
                    <a href="/hr/api_keys.php" class="mobile-menu-tile <?php echo $current_page === 'hr-api-keys' ? 'active' : ''; ?>">
                        <i class="fas fa-key" aria-hidden="true"></i>
                        <span>คีย์ API ภายนอก</span>
                    </a>
                    <a href="/hr/settings.php" class="mobile-menu-tile <?php echo $current_page === 'hr-settings' ? 'active' : ''; ?>">
                        <i class="fas fa-cog" aria-hidden="true"></i>
                        <span>ตั้งค่าระบบ</span>
                    </a>
                    <?php endif; ?>
                </nav>
            </details>
            <?php endif; ?>

            <div class="mobile-menu-logout-wrap">
                <form method="post" action="/logout.php" class="mobile-menu-logout-form">
                    <?php echo csrfField(); ?>
                    <button type="submit" class="mobile-menu-logout-btn whitespace-nowrap" aria-label="ออกจากระบบ TP-HR">
                        <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
                        <span>ออกจากระบบ</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function tpHrMobileMenuHideBottomTabs(hide) {
    document.body.classList.toggle('tp-hr-mobile-menu-open', !!hide);
    var tab = document.getElementById('tpHrMobileBottomTab');
    if (!tab) return;
    if (hide) tab.setAttribute('aria-hidden', 'true');
    else tab.removeAttribute('aria-hidden');
}

function openMobileMenu() {
    var sheet = document.getElementById('mobileSidebar');
    if (!sheet) return;
    sheet.classList.remove('hidden');
    tpHrMobileMenuHideBottomTabs(true);
    if (typeof uiLockBodyScroll === 'function') uiLockBodyScroll(true);
    else document.body.style.overflow = 'hidden';
    requestAnimationFrame(function () {
        var closeBtn = sheet.querySelector('.mobile-menu-close');
        if (closeBtn && typeof closeBtn.focus === 'function') closeBtn.focus();
    });
}

function closeMobileMenu() {
    var sheet = document.getElementById('mobileSidebar');
    if (sheet) sheet.classList.add('hidden');
    tpHrMobileMenuHideBottomTabs(false);
    if (typeof uiLockBodyScroll === 'function') uiLockBodyScroll(false);
    else document.body.style.overflow = '';
    var menuBtn = document.getElementById('mobileMenuBtn');
    if (menuBtn && typeof menuBtn.focus === 'function') menuBtn.focus();
}

document.getElementById('mobileMenuBtn')?.addEventListener('click', openMobileMenu);

(function () {
    var sheet = document.getElementById('mobileSidebar');
    if (!sheet) return;
    sheet.addEventListener('click', function (ev) {
        var link = ev.target.closest('a[href]');
        if (link && link.getAttribute('href') && link.getAttribute('href').indexOf('#') !== 0) {
            closeMobileMenu();
        }
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') return;
        if (sheet.classList.contains('hidden')) return;
        closeMobileMenu();
    });
})();

window.addEventListener('pageshow', function (ev) {
    if (!ev.persisted) return;
    if (typeof closeMobileMenu === 'function') closeMobileMenu();
});
</script>

<!-- Main Content -->
<main id="tp-hr-main" class="<?php echo htmlspecialchars($tp_hr_main_native_class, ENT_QUOTES, 'UTF-8'); ?>">
<div class="tp-native-stack--page min-w-0">
