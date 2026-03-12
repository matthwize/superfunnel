(function () {
  try {
    if (typeof window === "undefined" || typeof document === "undefined") return;
    if (navigator.webdriver) return;

    var ua = (navigator.userAgent || "").toLowerCase();
    if (/bot|crawl|spider|headless|slurp|preview|lighthouse|pagespeed|gtmetrix|pingdom/.test(ua)) return;

    if (document.prerendering || document.visibilityState === "prerender") return;

    var cfg = window.SuperfunnelTrack || {};
    var timeoutMs = Number(cfg.timeoutMs || 1800000);
    var qualifyDelayMs = Number(cfg.qualifyDelayMs || 1500);
    var endpointFast = String(cfg.endpointFast || "/superfunnel-track");
    var endpointRest = String(cfg.endpointRest || "");

    var path = window.location.pathname || "/";
    path = path.replace(/\/+/g, "/");
    if (path.length > 1) path = path.replace(/\/+$/, "");
    if (!path) path = "/";
    if (path.indexOf("order-received/") !== -1) path = path.replace(/(.*order-received)\/.*$/, "$1");

    function rand() {
      return Math.random().toString(36).slice(2);
    }
    function makeId(prefix) {
      return prefix + "_" + rand() + rand() + Date.now().toString(36);
    }

    var sessionKey = "superfunnel_session_id";
    var seenKey = "superfunnel_session_last_seen";
    var stepKey = "superfunnel_session_step";

    var now = Date.now();
    var sessionId = "";
    var lastSeen = 0;

    try {
      sessionId = localStorage.getItem(sessionKey) || "";
      lastSeen = parseInt(localStorage.getItem(seenKey) || "0", 10) || 0;

      if (!sessionId || !lastSeen || now - lastSeen > timeoutMs) {
        sessionId = makeId("sf");
        localStorage.setItem(sessionKey, sessionId);
        localStorage.setItem(stepKey, "0");
      }

      localStorage.setItem(seenKey, String(now));
    } catch (e) {
      sessionId = makeId("sf");
    }

    if (!sessionId) return;

    var pageToken = makeId("pv");
    var sentKey = "superfunnel_sent_" + pageToken;
    var qualified = false;

    function nextStep() {
      try {
        var current = parseInt(localStorage.getItem(stepKey) || "0", 10) || 0;
        current++;
        localStorage.setItem(stepKey, String(current));
        localStorage.setItem(seenKey, String(Date.now()));
        return current;
      } catch (e) {
        return 1;
      }
    }

    function bodyParams() {
      var step = nextStep();
      var body = new URLSearchParams();
      body.append("path", path);
      body.append("session_id", sessionId);
      body.append("page_token", pageToken);
      body.append("step_number", String(step));
      return body;
    }

    function post(url, body) {
      return fetch(url, {
        method: "POST",
        credentials: "same-origin",
        keepalive: true,
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: body.toString(),
      });
    }

    function send() {
      try {
        if (sessionStorage.getItem(sentKey)) return;
        sessionStorage.setItem(sentKey, "1");
      } catch (e) {}

      var body = bodyParams();

      if (endpointFast) {
        post(endpointFast, body)
          .then(function (res) {
            if (res && res.status && res.status < 400) return;
            if (endpointRest) return post(endpointRest, body);
          })
          .catch(function () {
            if (endpointRest) {
              post(endpointRest, body).catch(function () {});
            }
          });

        return;
      }

      if (endpointRest) {
        post(endpointRest, body).catch(function () {});
      }
    }

    function qualify() {
      if (qualified) return;
      if (document.visibilityState && document.visibilityState !== "visible") return;
      qualified = true;
      cleanup();
      send();
    }

    var opts = { passive: true, once: true };
    function cleanup() {
      window.removeEventListener("scroll", qualify, opts);
      window.removeEventListener("pointerdown", qualify, opts);
      window.removeEventListener("keydown", qualify, opts);
      window.removeEventListener("touchstart", qualify, opts);
    }

    window.addEventListener("scroll", qualify, opts);
    window.addEventListener("pointerdown", qualify, opts);
    window.addEventListener("keydown", qualify, opts);
    window.addEventListener("touchstart", qualify, opts);

    setTimeout(qualify, qualifyDelayMs);
  } catch (err) {
    // never block page
  }
})();