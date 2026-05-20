<?php // footer.php ?>

</main>

<!-- Wave separator — fill matches footer bg -->
<div class="footer-wave-wrap" aria-hidden="true">
    <svg viewBox="0 0 1440 52" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M0,26 C360,52 720,0 1080,26 C1260,39 1360,20 1440,26 L1440,52 L0,52 Z" fill="#FFF6D2"/>
    </svg>
</div>

<footer class="site-footer">
<div class="footer-inner">

    <div class="container footer-grid">

        <!-- Brand -->
        <div class="footer-col footer-brand-col">
            <a href="<?= BASE_URL ?>index.php" class="footer-logo-lockup">
                <img src="<?= BASE_URL ?>assets/images/logo.png" alt="DysonConnect logo" class="footer-logo-img">
                <div class="footer-logo-text">
                    <span class="footer-logo-name">DysonConnect</span>
                    <span class="footer-logo-sub">Regional Bus Booking</span>
                </div>
            </a>

            <p class="footer-desc">A prototype online bus booking platform serving intercity routes across Victoria &amp; New South Wales. Built for DWIN309 at Kent Institute Australia.</p>

            <div class="footer-region-pills">
                <span class="footer-region-pill">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    Victoria
                </span>
                <span class="footer-region-pill">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    NSW
                </span>
                <span class="footer-region-pill footer-region-pill--amber">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                    Express
                </span>
            </div>
        </div>

        <!-- Navigate -->
        <div class="footer-col">
            <h4 class="footer-heading">Navigate</h4>
            <ul class="footer-list">
                <li><a href="<?= BASE_URL ?>index.php">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
                    Home
                </a></li>
                <li><a href="<?= BASE_URL ?>routes.php">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                    View All Routes
                </a></li>
                <li><a href="<?= BASE_URL ?>search.php">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                    Book a Trip
                </a></li>
                <li><a href="<?= BASE_URL ?>my_bookings.php">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    My Bookings
                </a></li>
                <li><a href="<?= BASE_URL ?>register.php">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Create Account
                </a></li>

            </ul>
        </div>

        <!-- Popular Routes -->
        <div class="footer-col">
            <h4 class="footer-heading">Popular Routes</h4>
            <ul class="footer-list footer-routes">
            <?php
            global $conn;
            $footerRoutes = [];
            if (isset($conn)) {
                $fRes = $conn->query("SELECT origin, destination FROM routes WHERE status='active' ORDER BY base_price DESC");
                $fSeen = [];
                while ($fr = $fRes->fetch_assoc()) {
                    if (!isset($fSeen[$fr['origin']]) && count($footerRoutes) < 5) {
                        $fSeen[$fr['origin']] = true;
                        $footerRoutes[] = $fr;
                    }
                }
            }
            if (empty($footerRoutes)) {
                $footerRoutes = [
                    ['origin'=>'Melbourne CBD','destination'=>'Sydney'],
                    ['origin'=>'Sydney','destination'=>'Newcastle'],
                    ['origin'=>'Geelong','destination'=>'Ballarat'],
                ];
            }
            foreach ($footerRoutes as $fr): ?>
            <li>
                <a href="<?= BASE_URL ?>search.php?origin=<?= urlencode($fr['origin']) ?>&destination=<?= urlencode($fr['destination']) ?>" class="footer-route-link">
                    <span class="frl-from"><?= htmlspecialchars($fr['origin'], ENT_QUOTES) ?></span>
                    <svg class="frl-arrow" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    <span class="frl-to"><?= htmlspecialchars($fr['destination'], ENT_QUOTES) ?></span>
                </a>
            </li>
            <?php endforeach; ?>
            </ul>
        </div>

        <!-- On Board -->
        <div class="footer-col">
            <h4 class="footer-heading">On Board</h4>
            <ul class="footer-onboard-list">
                <li class="fob-item"><span class="fob-icon fob-icon--blue"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></span>Express Services</li>
                <li class="fob-item"><span class="fob-icon fob-icon--amber"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="2.5"/><path d="M8 8h8M8 12h8M8 16h4"/></svg></span>Wi-Fi on Board</li>
                <li class="fob-item"><span class="fob-icon fob-icon--blue"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg></span>Luggage Storage</li>
                <li class="fob-item"><span class="fob-icon fob-icon--amber"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M6 20v-1a6 6 0 0 1 12 0v1"/></svg></span>Wheelchair Access</li>
                <li class="fob-item"><span class="fob-icon fob-icon--blue"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></span>5 Payment Methods</li>
                <li class="fob-item"><span class="fob-icon fob-icon--amber"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>Instant E-Ticket</li>
            </ul>
        </div>

    </div>

    <!-- Bottom bar — navy background -->
    <div class="footer-bottom">
        <div class="container footer-bottom-inner">
            <p class="footer-copy">This website was created for the final assessment for <strong>DWIN309 &ndash; Developing Web Information Systems</strong> at <strong>Kent Institute Australia</strong>.</p>
            <div class="footer-students">
                <span>K241034 &middot; Bibek Subedi</span>
                <span class="fst-dot"></span>
                <span>K240381 &middot; Wasik Gaus</span>
                <span class="fst-dot"></span>
                <span>K241054 &middot; Santosh Silwal</span>
                <span class="fst-dot"></span>
                <span>K231952 &middot; Sushil Bhusal</span>
            </div>
        </div>
    </div>

</div>
</footer>

<script src="<?= BASE_URL ?>assets/js/script.js"></script>
</body>
</html>
