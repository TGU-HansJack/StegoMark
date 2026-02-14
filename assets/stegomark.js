(function () {
    "use strict";

    var bootNode = document.getElementById("stegomark-bootstrap");
    if (!bootNode) {
        return;
    }

    var boot = {};
    try {
        boot = JSON.parse(bootNode.textContent || "{}");
    } catch (e) {
        return;
    }

    var ZW_ZERO = "\u200b";
    var ZW_ONE = "\u200c";
    var ZW_START = "\u200d\u2062";
    var ZW_END = "\u2063\u2060";

    function clamp(n, min, max) {
        n = Number(n);
        if (n !== n) { // NaN check (older browsers)
            return min;
        }
        return Math.max(min, Math.min(max, n));
    }

    function nowSec() {
        return Math.floor(Date.now() / 1000);
    }

    function hashText(input) {
        var str = String(input || "");
        var hash = 2166136261;
        for (var i = 0; i < str.length; i++) {
            hash ^= str.charCodeAt(i);
            hash += (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24);
        }
        return ("00000000" + (hash >>> 0).toString(16)).slice(-8);
    }

    function utf8ToBase64Url(str) {
        if (window.TextEncoder) {
            var bytes = new TextEncoder().encode(str);
            var binary = "";
            for (var i = 0; i < bytes.length; i++) {
                binary += String.fromCharCode(bytes[i]);
            }
            return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/g, "");
        }
        var escaped = unescape(encodeURIComponent(str));
        return btoa(escaped).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/g, "");
    }

    function tokenToPacket(token) {
        if (!token) {
            return "";
        }
        var bits = "";
        for (var i = 0; i < token.length; i++) {
            var b = token.charCodeAt(i).toString(2);
            bits += ("00000000" + b).slice(-8);
        }
        var out = ZW_START;
        for (var j = 0; j < bits.length; j++) {
            out += bits[j] === "1" ? ZW_ONE : ZW_ZERO;
        }
        return out + ZW_END;
    }

    function payloadToPacket(payload) {
        var token = utf8ToBase64Url(JSON.stringify(payload || {}));
        return tokenToPacket(token);
    }

    function svgDataUrl(svg) {
        var encoded = encodeURIComponent(String(svg || ""))
            .replace(/%0A/g, "")
            .replace(/%20/g, " ")
            .replace(/%3D/g, "=")
            .replace(/%3A/g, ":")
            .replace(/%2F/g, "/");
        return "data:image/svg+xml;charset=utf-8," + encoded;
    }

    function makeRng(seed) {
        var x = (seed >>> 0) || 0x12345678;
        return function () {
            x ^= x << 13;
            x ^= x >>> 17;
            x ^= x << 5;
            return (x >>> 0) / 4294967296;
        };
    }

    function findContentContainer() {
        var custom = (boot.dynamic && boot.dynamic.contentSelector) ? String(boot.dynamic.contentSelector).trim() : "";
        var selectors = [];
        if (custom) {
            selectors.push(custom);
        }
        selectors.push(
            "[data-post-content-body]",
            "[data-post-content]",
            ".post-content",
            ".entry-content",
            ".entry-content.fmt",
            "article"
        );
        for (var i = 0; i < selectors.length; i++) {
            try {
                var node = document.querySelector(selectors[i]);
                if (node) {
                    return node;
                }
            } catch (e) {}
        }
        return document.body;
    }

    function getTextNodes(root) {
        var out = [];
        if (!root || !document.createTreeWalker) {
            return out;
        }
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode: function (node) {
                if (!node || !node.nodeValue) {
                    return NodeFilter.FILTER_REJECT;
                }
                var text = node.nodeValue.replace(/\s+/g, "");
                if (text.length < 12) {
                    return NodeFilter.FILTER_REJECT;
                }
                return NodeFilter.FILTER_ACCEPT;
            }
        });
        while (walker.nextNode()) {
            out.push(walker.currentNode);
        }
        return out;
    }

    function getTextNodesIn(root, minLen) {
        var out = [];
        minLen = Math.max(0, Math.min(500, Number(minLen || 0)));
        if (!root || !document.createTreeWalker) {
            return out;
        }
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode: function (node) {
                if (!node || !node.nodeValue) {
                    return NodeFilter.FILTER_REJECT;
                }
                var p = node.parentNode;
                if (p && p.nodeName && /^(SCRIPT|STYLE|NOSCRIPT|TEXTAREA)$/i.test(p.nodeName)) {
                    return NodeFilter.FILTER_REJECT;
                }
                var text = node.nodeValue.replace(/\s+/g, "");
                if (text.length < minLen) {
                    return NodeFilter.FILTER_REJECT;
                }
                return NodeFilter.FILTER_ACCEPT;
            }
        });
        while (walker.nextNode()) {
            out.push(walker.currentNode);
        }
        return out;
    }

    function parseSelectorList(raw) {
        var s = String(raw || "");
        if (!s.trim()) {
            return [];
        }
        return s.split(/[\r\n,]+/).map(function (x) {
            return String(x || "").trim();
        }).filter(function (x) {
            return !!x;
        });
    }

    function applyExtraContainerInjection() {
        var ex = boot.extra || {};
        if (!(Number(ex.enabled) === 1)) {
            return;
        }
        var algorithms = Array.isArray(boot.algorithms) ? boot.algorithms : [];
        if (algorithms.indexOf("char") === -1) {
            return;
        }
        var packet = "";
        if (boot.serverPacket) {
            packet = String(boot.serverPacket);
        } else if (boot.token) {
            packet = tokenToPacket(String(boot.token));
        }
        if (!packet) {
            return;
        }

        var selectors = parseSelectorList(ex.selectors || "");
        if (!selectors.length) {
            return;
        }

        var minLen = clamp(ex.minLength != null ? Number(ex.minLength) : 24, 0, 500);
        var maxInserts = clamp(ex.maxInserts != null ? Number(ex.maxInserts) : 18, 1, 500);
        var inserted = 0;

        for (var si = 0; si < selectors.length; si++) {
            if (inserted >= maxInserts) {
                break;
            }
            var sel = selectors[si];
            var els = [];
            try {
                els = document.querySelectorAll(sel);
            } catch (eSel) {
                continue;
            }
            for (var ei = 0; ei < els.length; ei++) {
                if (inserted >= maxInserts) {
                    break;
                }
                var el = els[ei];
                if (!el || !el.getAttribute) {
                    continue;
                }
                try {
                    if (el.closest && (el.closest("#stegomark-root") || el.closest(".stegomark-overlay"))) {
                        continue;
                    }
                } catch (eClose) {}
                if (el.getAttribute("data-sm-injected") === "1") {
                    continue;
                }

                var nodes = getTextNodesIn(el, minLen);
                if (!nodes.length) {
                    continue;
                }

                var seed = parseInt(hashText("ex:" + sel + ":" + ei + ":" + (boot.token || "")), 16) >>> 0;
                var did = false;
                for (var ni = 0; ni < nodes.length; ni++) {
                    var node = nodes[ni];
                    if (!node || !node.nodeValue) {
                        continue;
                    }
                    if (String(node.nodeValue).indexOf(ZW_START) !== -1) {
                        continue;
                    }
                    insertPacketIntoNode(node, packet, seed + ni, "random");
                    did = true;
                    inserted++;
                    break;
                }
                if (did) {
                    try {
                        el.setAttribute("data-sm-injected", "1");
                    } catch (eMark) {}
                }
            }
        }
    }

    function resolveCopyCount(strength, candidateCount) {
        var base = 2;
        if (strength === "weak") {
            base = 1;
        } else if (strength === "strong") {
            base = 4;
        }
        return Math.max(1, Math.min(candidateCount, base));
    }

    function insertPacketIntoNode(node, packet, seed, distribution) {
        if (!node || !packet) {
            return;
        }
        var text = node.nodeValue || "";
        if (text.length < 2) {
            node.nodeValue = text + packet;
            return;
        }
        var positions = [];
        if (distribution === "sentence") {
            for (var i = 0; i < text.length; i++) {
                var ch = text.charAt(i);
                if ("。！？!?;；.…".indexOf(ch) !== -1) {
                    positions.push(i + 1);
                }
            }
        } else if (distribution === "paragraph") {
            positions.push(Math.max(1, Math.floor(text.length / 3)));
            positions.push(Math.max(1, Math.floor(text.length * 0.66)));
        }
        if (positions.length === 0) {
            for (var j = 0; j < text.length; j++) {
                if (text.charAt(j).trim() !== "") {
                    positions.push(j + 1);
                }
            }
        }
        if (positions.length === 0) {
            positions.push(Math.floor(text.length / 2));
        }
        var pos = positions[Math.abs(seed) % positions.length];
        node.nodeValue = text.slice(0, pos) + packet + text.slice(pos);
    }

    function splitCodePoints(text) {
        try {
            return Array.from(String(text || ""));
        } catch (e) {
            return String(text || "").split("");
        }
    }

    function insertPacketIntoPlainText(text, packet, seed, preferSentence) {
        if (!packet) {
            return String(text || "");
        }
        var raw = String(text || "");
        if (!raw) {
            return packet;
        }
        if (raw.indexOf(ZW_START) !== -1) {
            return raw;
        }

        var chars = splitCodePoints(raw);
        if (chars.length < 2) {
            chars.push(packet);
            return chars.join("");
        }

        var positions = [];
        if (preferSentence) {
            var punct = "。！？!?;；.…";
            for (var i = 0; i < chars.length; i++) {
                if (punct.indexOf(chars[i]) !== -1) {
                    positions.push(i + 1);
                }
            }
        }

        // Avoid edges: deleting appended footer often trims the tail.
        if (positions.length === 0) {
            positions.push(Math.max(1, Math.floor(chars.length * 0.33)));
            positions.push(Math.max(1, Math.floor(chars.length * 0.6)));
        }

        var safe = [];
        for (var j = 0; j < positions.length; j++) {
            var p = positions[j];
            if (p >= 2 && p <= chars.length - 2) {
                safe.push(p);
            }
        }
        if (safe.length > 0) {
            positions = safe;
        }

        var pos = positions[Math.abs(seed) % positions.length];
        chars.splice(pos, 0, packet);
        return chars.join("");
    }

    function injectPacket(packet) {
        if (!packet) {
            return;
        }
        var cfg = boot.dynamic || {};
        var root = findContentContainer();
        var nodes = getTextNodes(root);
        if (!nodes.length) {
            return;
        }
        var copies = resolveCopyCount(String(cfg.strength || "medium"), nodes.length);
        for (var i = 0; i < copies; i++) {
            var seed = parseInt(hashText(packet + ":" + i), 16);
            var idx = Math.abs(seed) % nodes.length;
            insertPacketIntoNode(nodes[idx], packet, seed, String(cfg.distribution || "paragraph"));
        }
    }

    function injectCustomCss() {
        if (!(boot.page && Number(boot.page.isSingle) === 1) || !(Number(boot.cid) > 0)) {
            return;
        }
        var css = String(boot.customCss || "");
        if (!css) {
            return;
        }
        var style = document.createElement("style");
        style.id = "stegomark-custom-css";
        style.textContent = css;
        document.head.appendChild(style);
    }

    function renderTemplate(tpl, map) {
        return String(tpl || "").replace(/\{([a-zA-Z0-9_]+)\}/g, function (_, key) {
            return map.hasOwnProperty(key) ? String(map[key]) : "";
        });
    }

    function injectCustomLayout() {
        if (!(boot.page && Number(boot.page.isSingle) === 1) || !(Number(boot.cid) > 0)) {
            return;
        }
        var tpl = String(boot.customLayoutHtml || "");
        if (!tpl) {
            return;
        }
        var userName = (boot.user && Number(boot.user.logged) === 1) ? (boot.user.name || "") : "";
        var map = {
            title: boot.title || "",
            url: boot.url || "",
            site: boot.site || "",
            user: userName,
            watermark_id: boot.watermarkId || "",
            site_fingerprint: boot.siteFingerprint || "",
            visitor_id: (boot.visitor && boot.visitor.visitorId) ? boot.visitor.visitorId : "",
            time: new Date().toLocaleString()
        };
        var html = renderTemplate(tpl, map);
        var box = document.createElement("div");
        box.className = "stegomark-custom-layout";
        box.innerHTML = html;
        var mount = document.getElementById("stegomark-root") || document.body;
        mount.appendChild(box);
    }

    function sendAction(doName, payload) {
        if (!boot.actionUrl) {
            return;
        }
        var form = new FormData();
        form.append("do", doName);
        if (boot.actionSig && Number(boot.actionSig.enabled) === 1) {
            form.append("sm_ts", boot.actionSig.ts == null ? "" : String(boot.actionSig.ts));
            form.append("sm_sig", boot.actionSig.sig == null ? "" : String(boot.actionSig.sig));
        }
        for (var key in payload) {
            if (payload.hasOwnProperty(key)) {
                form.append(key, payload[key] == null ? "" : String(payload[key]));
            }
        }
        try {
            if (navigator.sendBeacon) {
                navigator.sendBeacon(boot.actionUrl, form);
                return;
            }
        } catch (e) {}
        try {
            fetch(boot.actionUrl, { method: "POST", body: form, credentials: "same-origin", keepalive: true });
        } catch (e2) {}
    }

    function buildVisitorIdClient() {
        var base = [
            navigator.userAgent || "",
            navigator.language || "",
            screen.width + "x" + screen.height,
            new Date().getTimezoneOffset(),
            nowSec()
        ].join("|");
        return hashText(base) + hashText(base + ":2") + hashText(base + ":3");
    }

    function applyDynamicInjection() {
        if (!(boot.dynamic && Number(boot.dynamic.enabled) === 1)) {
            return;
        }
        if (!(boot.page && Number(boot.page.isSingle) === 1) || !(Number(boot.cid) > 0)) {
            return;
        }

        var mode = (boot.integrity && boot.integrity.mode) ? String(boot.integrity.mode) : "off";
        if (mode !== "off") {
            // In signed/sealed mode, client cannot mint a new valid token (no secret).
            // We just re-inject the server packet to improve distribution.
            var p = "";
            if (boot.serverPacket) {
                p = String(boot.serverPacket);
            } else if (boot.token) {
                p = tokenToPacket(String(boot.token));
            }
            if (p) {
                injectPacket(p);
            }
            return;
        }

        var payload = {};
        var src = boot.payloadBase || {};
        for (var k in src) {
            if (src.hasOwnProperty(k)) {
                payload[k] = src[k];
            }
        }
        payload.ts = nowSec();
        payload.cvi = buildVisitorIdClient();
        injectPacket(payloadToPacket(payload));
    }

    function applyServerPacket() {
        if (!boot.serverPacket) {
            return;
        }
        if (!(boot.page && Number(boot.page.isSingle) === 1) || !(Number(boot.cid) > 0)) {
            return;
        }
        var algorithms = Array.isArray(boot.algorithms) ? boot.algorithms : [];
        if (algorithms.indexOf("char") === -1) {
            return;
        }
        injectPacket(String(boot.serverPacket));
    }

    function buildBgSvg(line1, line2, seed) {
        var rng = makeRng(seed);
        var w = 520;
        var h = 520;
        var x1 = Math.floor(24 + rng() * 40);
        var y1 = Math.floor(80 + rng() * 40);
        var x2 = Math.floor(24 + rng() * 40);
        var y2 = Math.floor(240 + rng() * 60);

        return (
            '<svg xmlns="http://www.w3.org/2000/svg" width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + " " + h + '" overflow="hidden">' +
            '<rect width="100%" height="100%" fill="none"/>' +
            '<g fill="#10203a" fill-opacity="1">' +
            '<text x="' + x1 + '" y="' + y1 + '" font-family="ui-monospace, SFMono-Regular, Menlo, Consolas, monospace" font-size="12" letter-spacing="1.2">' +
            String(line1 || "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;") +
            "</text>" +
            '<text x="' + x2 + '" y="' + y2 + '" font-family="ui-monospace, SFMono-Regular, Menlo, Consolas, monospace" font-size="12" letter-spacing="1.2">' +
            String(line2 || "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;") +
            "</text>" +
            "</g></svg>"
        );
    }

    function buildNoisePngDataUrl(seed, size) {
        size = Math.max(32, Math.min(160, size || 96));
        var rng = makeRng(seed);
        var c = document.createElement("canvas");
        c.width = size;
        c.height = size;
        var ctx = c.getContext("2d");
        if (!ctx) {
            return "";
        }
        var img = ctx.createImageData(size, size);
        for (var i = 0; i < img.data.length; i += 4) {
            var r = 18;
            var g = 48;
            var b = 96;
            var a = Math.floor(rng() * 255);
            img.data[i] = r;
            img.data[i + 1] = g;
            img.data[i + 2] = b;
            img.data[i + 3] = a;
        }
        ctx.putImageData(img, 0, 0);
        try {
            return c.toDataURL("image/png");
        } catch (e) {
            return "";
        }
    }

    function drawCanvasPattern(canvas, seed, alpha, text) {
        var dpr = Math.max(1, Math.floor((window.devicePixelRatio || 1) * 100) / 100);
        var w = Math.max(1, window.innerWidth);
        var h = Math.max(1, window.innerHeight);

        canvas.width = Math.max(1, Math.floor(w * dpr));
        canvas.height = Math.max(1, Math.floor(h * dpr));
        canvas.style.width = w + "px";
        canvas.style.height = h + "px";

        var ctx = canvas.getContext("2d");
        if (!ctx) {
            return;
        }

        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, w, h);

        var rng = makeRng(seed);
        var angle = (rng() * 20 - 10) * (Math.PI / 180);
        ctx.translate(w / 2, h / 2);
        ctx.rotate(angle);
        ctx.translate(-w / 2, -h / 2);

        ctx.globalAlpha = clamp(alpha, 0.008, 0.06);
        ctx.fillStyle = "#123061";

        var step = 46;
        for (var y = -step; y < h + step; y += step) {
            for (var x = -step; x < w + step; x += step) {
                // Deterministic jitter
                var jx = (rng() - 0.5) * 10;
                var jy = (rng() - 0.5) * 10;
                var r = 0.5 + rng() * 1.1;
                ctx.beginPath();
                ctx.arc(x + jx, y + jy, r, 0, Math.PI * 2);
                ctx.fill();

                // Sparse micro-cross to increase screenshot persistence
                if (rng() > 0.92) {
                    var cx = x + jx + (rng() - 0.5) * 6;
                    var cy = y + jy + (rng() - 0.5) * 6;
                    ctx.fillRect(cx - 0.5, cy - 2.2, 1, 4.4);
                    ctx.fillRect(cx - 2.2, cy - 0.5, 4.4, 1);
                }
            }
        }

        var t = String(text || "").trim();
        if (!t) {
            return;
        }

        // Microtext: readable after screenshot enhancement (levels/contrast), still subtle in normal view.
        ctx.globalAlpha = clamp(alpha * 1.15, 0.008, 0.08);
        ctx.fillStyle = "#10203a";
        ctx.font = "10px ui-monospace, SFMono-Regular, Menlo, Consolas, monospace";
        ctx.textBaseline = "top";
        var stepX = 340;
        try {
            var tw = ctx.measureText(t).width || 0;
            if (tw > 0) {
                // Ensure repeated microtext does not overlap horizontally when text is long.
                stepX = Math.max(stepX, Math.ceil(tw + 140));
            }
        } catch (eTw) {}
        var stepY = 140;
        for (var ty = 18; ty < h + stepY; ty += stepY) {
            for (var tx = 18; tx < w + stepX; tx += stepX) {
                var jtx = (rng() - 0.5) * 14;
                var jty = (rng() - 0.5) * 10;
                ctx.fillText(t, tx + jtx, ty + jty);
            }
        }
    }

    function buildOverlay() {
        var visual = boot.visual || {};
        if (!Number(visual.bg) && !Number(visual.noise) && !Number(visual.canvas)) {
            return null;
        }
        var overlay = document.createElement("div");
        overlay.className = "stegomark-overlay";
        overlay.id = "stegomark-visual-overlay";
        if (boot.token) {
            overlay.setAttribute("data-sm-token", String(boot.token));
        }

        var enabledCount = 0;
        if (Number(visual.bg) === 1) enabledCount++;
        if (Number(visual.noise) === 1) enabledCount++;
        if (Number(visual.canvas) === 1) enabledCount++;
        var factor = enabledCount > 0 ? (1 / enabledCount) : 1;

        if (Number(visual.bg) === 1) {
            var bg = document.createElement("div");
            bg.className = "sm-layer sm-bg-layer";

            var dt = new Date();
            var dateStr = dt.getFullYear() + "-" + ("0" + (dt.getMonth() + 1)).slice(-2) + "-" + ("0" + dt.getDate()).slice(-2);
            var v = (boot.visitor && boot.visitor.visitorId) ? boot.visitor.visitorId : "";
            var userName = (boot.user && Number(boot.user.logged) === 1) ? (boot.user.name || "") : "";
            var line1 = [boot.site || "", boot.siteFingerprint || "", boot.watermarkId || ""].filter(Boolean).join(" • ");
            var line2 = [userName, v, dateStr].filter(Boolean).join(" • ");

            var bgSeed = parseInt(hashText((boot.watermarkId || "") + ":" + (boot.siteFingerprint || "")), 16) >>> 0;
            bg.style.backgroundImage = "url(\"" + svgDataUrl(buildBgSvg(line1, line2, bgSeed)) + "\")";

            var bgOpacity = clamp((visual.bgOpacity != null ? Number(visual.bgOpacity) : 0.02) * factor, 0, 0.2);
            overlay.style.setProperty("--sm-bg-opacity", String(bgOpacity));
            overlay.style.setProperty("--sm-bg-rotate", String(-14 - (bgSeed % 8)) + "deg");
            overlay.style.setProperty("--sm-bg-x", String((bgSeed % 29) - 14) + "px");
            overlay.style.setProperty("--sm-bg-y", String((bgSeed % 23) - 11) + "px");
            overlay.appendChild(bg);
        }

        if (Number(visual.noise) === 1) {
            var noise = document.createElement("div");
            noise.className = "sm-layer sm-noise-layer";
            var noiseSeed = parseInt(hashText((boot.visitor && boot.visitor.visitorId) ? boot.visitor.visitorId : (boot.watermarkId || "sm")), 16) >>> 0;
            var noiseUrl = buildNoisePngDataUrl(noiseSeed, 96);
            if (noiseUrl) {
                noise.style.backgroundImage = "url(" + noiseUrl + ")";
            }
            var noiseOpacity = clamp((visual.noiseOpacity != null ? Number(visual.noiseOpacity) : 0.015) * factor, 0, 0.2);
            overlay.style.setProperty("--sm-noise-opacity", String(noiseOpacity));
            overlay.style.setProperty("--sm-noise-rotate", String(6 + (noiseSeed % 9)) + "deg");
            overlay.style.setProperty("--sm-noise-x", String((noiseSeed % 17) - 8) + "px");
            overlay.style.setProperty("--sm-noise-y", String((noiseSeed % 13) - 6) + "px");
            overlay.appendChild(noise);
        }

        if (Number(visual.canvas) === 1) {
            var canvas = document.createElement("canvas");
            canvas.className = "sm-layer sm-canvas-layer";
            overlay.appendChild(canvas);

            var draw = function () {
                var seed = parseInt(hashText("c:" + ((boot.visitor && boot.visitor.visitorId) ? boot.visitor.visitorId : (boot.watermarkId || "sm"))), 16) >>> 0;
                var dt = new Date();
                var dateStr = dt.getFullYear() + "-" + ("0" + (dt.getMonth() + 1)).slice(-2) + "-" + ("0" + dt.getDate()).slice(-2);
                var v = (boot.visitor && boot.visitor.visitorId) ? boot.visitor.visitorId : "";
                var userName = (boot.user && Number(boot.user.logged) === 1) ? (boot.user.name || "") : "";
                var info = [boot.siteFingerprint || "", boot.watermarkId || "", userName, v, dateStr].filter(Boolean).join(" • ");
                var canvasOpacity = clamp((visual.canvasOpacity != null ? Number(visual.canvasOpacity) : 0.018) * factor, 0, 0.2);
                drawCanvasPattern(canvas, seed, canvasOpacity, info);
            };
            draw();
            window.addEventListener("resize", draw);
        }

        return overlay;
    }

    function setupCopyAppend() {
        if (!(boot.copy && Number(boot.copy.enabled) === 1)) {
            return;
        }
        if (!(boot.page && Number(boot.page.isSingle) === 1) || !(Number(boot.cid) > 0)) {
            return;
        }
        document.addEventListener("copy", function (ev) {
            var sel = window.getSelection ? window.getSelection() : null;
            if (!sel) {
                return;
            }
            var selected = String(sel.toString() || "");
            if (!selected.trim()) {
                return;
            }

            var userName = (boot.user && Number(boot.user.logged) === 1) ? (boot.user.name || "") : "";
            var map = {
                title: boot.title || "",
                url: boot.url || location.href,
                site: boot.site || location.host,
                user: userName,
                watermark_id: boot.watermarkId || "",
                site_fingerprint: boot.siteFingerprint || "",
                visitor_id: (boot.visitor && boot.visitor.visitorId) ? boot.visitor.visitorId : "",
                time: new Date().toLocaleString()
            };

            // Key fix: inject an extra hidden packet into the copied body text.
            // This keeps decoding possible even if the visible copyright appendix is removed.
            var copyPacket = "";
            if (boot.serverPacket) {
                copyPacket = String(boot.serverPacket);
            } else if (boot.token) {
                copyPacket = tokenToPacket(String(boot.token));
            }
            if (copyPacket) {
                var copySeed = parseInt(hashText("copy:" + (boot.token || boot.watermarkId || "")), 16) >>> 0;
                selected = insertPacketIntoPlainText(selected, copyPacket, copySeed, true);
            }

            var appendix = renderTemplate(boot.copy.template || "", map);
            var merged = selected + "\n\n" + appendix;

            if (ev.clipboardData && typeof ev.clipboardData.setData === "function") {
                ev.clipboardData.setData("text/plain", merged);
                ev.preventDefault();
            }

            if (boot.copy && Number(boot.copy.logEnabled) === 1) {
                sendAction("copy_log", {
                    cid: boot.cid || 0,
                    watermarkId: boot.watermarkId || "",
                    visitorId: map.visitor_id,
                    selectionLength: selected.length,
                    title: boot.title || "",
                    url: boot.url || location.href
                });
            }
        });
    }

    function maybeUnlockBlockedView() {
        if (!(boot.block && Number(boot.block.enabled) === 1)) {
            return;
        }
        var vid = "";
        try {
            vid = (boot.visitor && boot.visitor.visitorId) ? String(boot.visitor.visitorId) : "";
        } catch (e) {
            vid = "";
        }
        if (!vid) {
            try {
                vid = buildVisitorIdClient();
            } catch (e2) {
                vid = "";
            }
        }
        if (vid && vid.length >= 12) {
            document.documentElement.classList.add("sm-unlocked");
        }
    }

    function init() {
        maybeUnlockBlockedView();
        applyExtraContainerInjection();

        var isSingle = (boot.page && Number(boot.page.isSingle) === 1) && (Number(boot.cid) > 0);
        if (!isSingle) {
            return;
        }
        injectCustomCss();
        injectCustomLayout();
        applyServerPacket();
        applyDynamicInjection();
        setupCopyAppend();
        sendAction("ping", {
            cid: boot.cid || 0,
            watermarkId: boot.watermarkId || "",
            visitorId: (boot.visitor && boot.visitor.visitorId) ? boot.visitor.visitorId : buildVisitorIdClient(),
            url: boot.url || location.href
        });

        var overlay = buildOverlay();
        if (overlay) {
            var existing = document.getElementById("stegomark-visual-overlay");
            if (existing && existing.parentNode) {
                existing.parentNode.removeChild(existing);
            }
            document.body.appendChild(overlay);
        }
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
