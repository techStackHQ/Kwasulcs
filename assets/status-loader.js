// status-loader.js — reusable "cycle through status messages" loading
// indicator, shared across quiz generation, quiz grading/submission, and
// AI chat "thinking" states (see quiz.php / chat_ai.php / course.php).
//
// This is a PERCEIVED-progress improvement only, not a real progress bar —
// a single blocking API call gives no genuine granular progress to report,
// so this never implies a percentage, just rotates through short, stage-
// appropriate text so the wait feels alive instead of static.
(function () {
    /**
     * Starts cycling `el`'s text content through `messages` every
     * `intervalMs` (default 2200ms — AI chat's original, unchanged pacing;
     * chat responses typically return quickly, so chat callers keep
     * calling this with no `intervalMs`/`fadeMs` at all). Shows the first
     * message immediately. LOOPS continuously (wraps back to the first
     * message) for as long as it keeps running — this is deliberate: quiz
     * generation/grading can take 20-80s+, far longer than one pass
     * through a short message list, so this must not run out and go
     * static/blank while a request is still pending.
     *
     * `fadeMs` (default 0 — instant swap, AI chat's original behavior):
     * when set, each transition cross-dissolves over `fadeMs` via a CSS
     * opacity transition instead of an instant text swap — used by the
     * quiz loaders (Task 24), which are deliberately paced slower and
     * smoother than chat's. Quiz callers pass this; chat callers don't,
     * so chat's loader is completely unaffected by this addition.
     *
     * Returns a stop() function — call it in BOTH the success and error
     * paths of whatever request this loader is standing in for, so a
     * failed request never leaves a stale "Generating questions…"-type
     * message on screen with no indication anything went wrong. If the
     * real response arrives before a full cycle completes, that's fine —
     * just stop() and swap in the real result; nothing here artificially
     * delays that.
     */
    window.startStatusCycler = function (el, messages, intervalMs, fadeMs) {
        intervalMs = intervalMs || 2200;
        fadeMs = fadeMs || 0;
        if (!el || !messages || !messages.length) {
            return function stop() {};
        }

        var i = 0;
        var fadeTimeout = null;

        if (fadeMs > 0) {
            el.style.transition = 'opacity ' + fadeMs + 'ms ease';
            el.style.opacity = '1';
        }
        el.textContent = messages[0];

        var timer = setInterval(function () {
            i = (i + 1) % messages.length;
            if (fadeMs > 0) {
                // Cross-dissolve: fade out, swap the text once it's
                // invisible, fade back in — rather than an instant cut.
                el.style.opacity = '0';
                fadeTimeout = setTimeout(function () {
                    el.textContent = messages[i];
                    el.style.opacity = '1';
                }, fadeMs);
            } else {
                el.textContent = messages[i];
            }
        }, intervalMs);

        return function stop() {
            clearInterval(timer);
            if (fadeTimeout) clearTimeout(fadeTimeout);
        };
    };
})();
