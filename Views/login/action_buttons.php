<?php
/**
 * VIEW: login/action_buttons.php
 * CSS: .action-buttons-row | .btn-wrapper | .btn-circle-3d
 *      .btn-privacy | .btn-gallery | .btn-fb | .btn-wa | .btn-dl | .btn-yt
 *      .btn-text-3d | .skf-support-fab (নতুন, স্কোপড, ফাইলের ভেতরেই সংজ্ঞায়িত)
 * FIX: সব বাটন একই structure — <div.btn-wrapper> → <a.btn-circle-3d> → icon
 *      Download বাটনে আগে <a> এর ভেতরে <div> ছিল → layout ভাঙত
 * FIX: Privacy বাটনের আইকন ক্লাস অবৈধ ছিল (class="fab Privacy Policy")
 *      → বৈধ Font Awesome shield আইকন দিয়ে প্রতিস্থাপন করা হলো
 * ADD: কাস্টমার সাপোর্ট বাটন — এখন এটি স্বাধীন floating + draggable widget
 *      হিসেবে যোগ করা হলো (রঙিন গ্রেডিয়েন্ট + pulse/glow অ্যানিমেশন সহ)
 *      (assumption: লিংক /Support/ — প্রকৃত সাপোর্ট URL/হোয়াটসঅ্যাপ নম্বর
 *      দিলে এখানে বসিয়ে দেওয়া হবে)
 */
?>

<?php if (!defined('SKF_ACTION_BUTTONS_STYLE_RENDERED')): define('SKF_ACTION_BUTTONS_STYLE_RENDERED', true); ?>
<style>
/* ══ ফ্লোটিং ড্র্যাগেবল সাপোর্ট বাটন ═══════════════════════════
   পুরো পেজের উপরে ভাসবে, মাউস/টাচ দিয়ে টেনে নেওয়া যাবে।
   .skf- প্রিফিক্স দিয়ে স্কোপড, তাই কোনো বিদ্যমান CSS-এর সাথে সংঘর্ষ হবে না।
══════════════════════════════════════════════════════════════ */
.skf-support-fab {
    position: fixed;
    top: 55%;
    right: 18px;
    width: 62px;
    height: 62px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2000;
    cursor: grab;
    touch-action: none;
    user-select: none;
    text-decoration: none !important;

    background: linear-gradient(145deg, #ff6ec4, #7873f5, #4ade80);
    background-size: 220% 220%;
    animation: skfSupportGradient 5s ease infinite, skfSupportPulse 2.2s ease-in-out infinite;

    box-shadow:
        0 8px 20px rgba(0, 0, 0, 0.35),
        0 0 0 0 rgba(120, 115, 245, 0.55);
}

.skf-support-fab.skf-dragging {
    cursor: grabbing;
    animation-play-state: paused;
    transition: none !important;
}

.skf-support-fab i {
    font-size: 1.6rem;
    color: #ffffff;
    pointer-events: none;
}

/* রঙ বদলাতে থাকা ব্যাকগ্রাউন্ড */
@keyframes skfSupportGradient {
    0%   { background-position: 0% 50%; }
    50%  { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

/* চোখে পড়ার জন্য হালকা glow/pulse রিং */
@keyframes skfSupportPulse {
    0%   { box-shadow: 0 8px 20px rgba(0,0,0,0.35), 0 0 0 0 rgba(120, 115, 245, 0.55); }
    70%  { box-shadow: 0 8px 20px rgba(0,0,0,0.35), 0 0 0 16px rgba(120, 115, 245, 0); }
    100% { box-shadow: 0 8px 20px rgba(0,0,0,0.35), 0 0 0 0 rgba(120, 115, 245, 0); }
}

.skf-support-label {
    position: absolute;
    bottom: -22px;
    left: 50%;
    transform: translateX(-50%);
    font-size: .72rem;
    font-weight: 700;
    color: #ffffff;
    background: rgba(0, 0, 0, 0.55);
    padding: 2px 8px;
    border-radius: 8px;
    white-space: nowrap;
    pointer-events: none;
}

@media (max-width: 360px) {
    .skf-support-fab { width: 54px; height: 54px; }
    .skf-support-fab i { font-size: 1.35rem; }
}
</style>
<?php endif; ?>

<div class="action-buttons-row">
    <div class="btn-wrapper">
        <a href="https://www.facebook.com/share/1HATRCDFMu/"
           target="_blank" rel="noopener noreferrer"
           class="btn-circle-3d btn-fb" title="FACEBOOK">
            <i class="fab fa-facebook-f"></i>
        </a>
        <span class="btn-text-3d">Face</span>
    </div>
    <div class="btn-wrapper">
        <a href="https://wa.me/8801821933259"
           target="_blank" rel="noopener noreferrer"
           class="btn-circle-3d btn-wa" title="WHATSAPP">
            <i class="fab fa-whatsapp"></i>
        </a>
        <span class="btn-text-3d">হোয়াটসঅ্যাপ</span>
    </div>
    <div class="btn-wrapper">
        <a href="/Privacy/"
           target="_blank" rel="noopener noreferrer"
           class="btn-circle-3d btn-yt" title="চ্যানেল">
            <i class="fas fa-shield-alt"></i>
        </a>
        <span class="btn-text-3d">Privacy</span>
    </div>
</div>

<!-- ══ ফ্লোটিং ড্র্যাগেবল সাপোর্ট বাটন ══════════════════════════ -->
<a href="/Message/pages/contact.php/"
   id="skfSupportFab"
   target="_blank" rel="noopener noreferrer"
   class="skf-support-fab" title="কাস্টমার সাপোর্ট">
    <i class="fas fa-headset"></i>
    <span class="skf-support-label">সাপোর্ট</span>
</a>

<script>
(function () {
    'use strict';

    var fab = document.getElementById('skfSupportFab');
    if (!fab) {
        return;
    }

    var isDragging = false;
    var hasMoved = false;
    var startX = 0;
    var startY = 0;
    var startTop = 0;
    var startLeft = 0;
    var dragThreshold = 7; // পিক্সেল — এর কম নড়াচড়া হলে সেটাকে "ক্লিক" ধরা হবে

    function getPointerPosition(event) {
        if (event.touches && event.touches.length > 0) {
            return { x: event.touches[0].clientX, y: event.touches[0].clientY };
        }
        return { x: event.clientX, y: event.clientY };
    }

    function clampToViewport(top, left) {
        var maxTop = window.innerHeight - fab.offsetHeight - 8;
        var maxLeft = window.innerWidth - fab.offsetWidth - 8;

        top = Math.max(8, Math.min(top, maxTop));
        left = Math.max(8, Math.min(left, maxLeft));

        return { top: top, left: left };
    }

    function onDragStart(event) {
        isDragging = true;
        hasMoved = false;

        var pointer = getPointerPosition(event);
        var rect = fab.getBoundingClientRect();

        startX = pointer.x;
        startY = pointer.y;
        startTop = rect.top;
        startLeft = rect.left;

        // ফিক্সড পজিশনে সুইচ করা হচ্ছে (আগে top/right দিয়ে বসানো ছিল)
        fab.style.top = startTop + 'px';
        fab.style.left = startLeft + 'px';
        fab.style.right = 'auto';
        fab.style.bottom = 'auto';

        fab.classList.add('skf-dragging');
    }

    function onDragMove(event) {
        if (!isDragging) {
            return;
        }

        var pointer = getPointerPosition(event);
        var deltaX = pointer.x - startX;
        var deltaY = pointer.y - startY;

        if (Math.abs(deltaX) > dragThreshold || Math.abs(deltaY) > dragThreshold) {
            hasMoved = true;
        }

        if (hasMoved) {
            event.preventDefault();
            var clamped = clampToViewport(startTop + deltaY, startLeft + deltaX);
            fab.style.top = clamped.top + 'px';
            fab.style.left = clamped.left + 'px';
        }
    }

    function onDragEnd(event) {
        if (!isDragging) {
            return;
        }

        isDragging = false;
        fab.classList.remove('skf-dragging');

        // ড্র্যাগ করা হয়ে থাকলে ক্লিক (নেভিগেশন) বন্ধ করে দেওয়া হচ্ছে,
        // যাতে বাটন সরানোর সময় সাইটে চলে না যায়।
        if (hasMoved) {
            event.preventDefault();
        }
        hasMoved = false;
    }

    // মাউস ইভেন্ট
    fab.addEventListener('mousedown', onDragStart);
    document.addEventListener('mousemove', onDragMove);
    document.addEventListener('mouseup', onDragEnd);

    // টাচ ইভেন্ট (মোবাইল)
    fab.addEventListener('touchstart', onDragStart, { passive: true });
    document.addEventListener('touchmove', onDragMove, { passive: false });
    document.addEventListener('touchend', onDragEnd);

    // ক্লিক ইভেন্ট আটকানো, যদি সেটা আসলে ড্র্যাগ ছিল
    fab.addEventListener('click', function (event) {
        if (hasMoved) {
            event.preventDefault();
        }
    });

    // স্ক্রিন সাইজ পরিবর্তন হলে বাটন যেন viewport-এর বাইরে চলে না যায়
    window.addEventListener('resize', function () {
        var rect = fab.getBoundingClientRect();
        if (fab.style.left && fab.style.top) {
            var clamped = clampToViewport(rect.top, rect.left);
            fab.style.top = clamped.top + 'px';
            fab.style.left = clamped.left + 'px';
        }
    });
})();
</script>