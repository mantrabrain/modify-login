/**
 * Authlify ALTCHA solver (no dependencies, no network).
 *
 * Finds the number n (0..max) for which SHA-256(salt + n) equals the
 * challenge. Works as a web worker (postMessage) and, as a fallback, on the
 * page itself as window.authlifyAltchaSolve(). Pure-JS SHA-256 so it also
 * runs on plain-HTTP sites, where crypto.subtle is unavailable.
 *
 * @since 3.0.0
 */
(function (root) {
    'use strict';

    var K = [
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
        0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
        0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
        0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
        0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
    ];
    var W = new Array(64);

    /**
     * SHA-256 of an ASCII string, as lowercase hex.
     *
     * @param {string} str Input (ASCII).
     * @return {string}
     */
    function sha256(str) {
        var i, j, t, words = [], bits = str.length * 8;
        for (i = 0; i < str.length; i++) {
            words[i >> 2] |= (str.charCodeAt(i) & 0xff) << (24 - (i % 4) * 8);
        }
        words[bits >> 5] |= 0x80 << (24 - bits % 32);
        words[(((bits + 64) >> 9) << 4) + 15] = bits;

        var h0 = 0x6a09e667, h1 = 0xbb67ae85, h2 = 0x3c6ef372, h3 = 0xa54ff53a,
            h4 = 0x510e527f, h5 = 0x9b05688c, h6 = 0x1f83d9ab, h7 = 0x5be0cd19;

        for (j = 0; j < words.length; j += 16) {
            var a = h0, b = h1, c = h2, d = h3, e = h4, f = h5, g = h6, h = h7;
            for (t = 0; t < 64; t++) {
                if (t < 16) {
                    W[t] = words[j + t] | 0;
                } else {
                    var w15 = W[t - 15], w2 = W[t - 2];
                    var s0 = ((w15 >>> 7) | (w15 << 25)) ^ ((w15 >>> 18) | (w15 << 14)) ^ (w15 >>> 3);
                    var s1 = ((w2 >>> 17) | (w2 << 15)) ^ ((w2 >>> 19) | (w2 << 13)) ^ (w2 >>> 10);
                    W[t] = (W[t - 16] + s0 + W[t - 7] + s1) | 0;
                }
                var S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
                var t1 = (h + S1 + ((e & f) ^ (~e & g)) + K[t] + W[t]) | 0;
                var S0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
                var t2 = (S0 + ((a & b) ^ (a & c) ^ (b & c))) | 0;
                h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + t2) | 0;
            }
            h0 = (h0 + a) | 0; h1 = (h1 + b) | 0; h2 = (h2 + c) | 0; h3 = (h3 + d) | 0;
            h4 = (h4 + e) | 0; h5 = (h5 + f) | 0; h6 = (h6 + g) | 0; h7 = (h7 + h) | 0;
        }

        var out = '', hs = [h0, h1, h2, h3, h4, h5, h6, h7];
        for (i = 0; i < 8; i++) {
            out += ('00000000' + (hs[i] >>> 0).toString(16)).slice(-8);
        }
        return out;
    }

    /**
     * Search a range of numbers.
     *
     * @return {number} The number, or -1.
     */
    function search(challenge, salt, from, to) {
        for (var n = from; n <= to; n++) {
            if (sha256(salt + n) === challenge) {
                return n;
            }
        }
        return -1;
    }

    var isWorker = typeof document === 'undefined' && typeof root.postMessage === 'function' && typeof importScripts === 'function';

    if (isWorker) {
        root.onmessage = function (event) {
            var d = event.data || {};
            root.postMessage({ number: search(String(d.challenge), String(d.salt), 0, Number(d.max) || 100000) });
        };
        return;
    }

    /**
     * Main-thread fallback: solve in slices so the page stays responsive.
     *
     * @param {Object}   ch   Challenge.
     * @param {Function} done Called with the number, or -1.
     */
    root.authlifyAltchaSolve = function (ch, done) {
        var max = Number(ch.maxnumber) || 100000, from = 0, step = 2000;
        (function slice() {
            var to = Math.min(max, from + step - 1);
            var n = search(String(ch.challenge), String(ch.salt), from, to);
            if (n >= 0 || to >= max) {
                done(n);
                return;
            }
            from = to + 1;
            setTimeout(slice, 0);
        })();
    };
    root.authlifySha256 = sha256;
})(typeof self !== 'undefined' ? self : this);
