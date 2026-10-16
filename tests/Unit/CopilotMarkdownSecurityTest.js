/**
 * CopilotMarkdownSecurityTest.js
 * Frontend Security Regression Test for Copilot Markdown Renderer
 * Phase B3.6.10J - DOMPurify Fail-Closed Guard & 4-Combination Security Matrix
 */

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// 1. Load actual production implementation from master.blade.php
const masterBladePath = path.resolve(__dirname, '../../resources/views/backoffice/master.blade.php');
if (!fs.existsSync(masterBladePath)) {
    throw new Error(`master.blade.php not found at ${masterBladePath}`);
}
const bladeContent = fs.readFileSync(masterBladePath, 'utf8');

// Extract renderCopilotMarkdown function
const startMarker = 'window.renderCopilotMarkdown = function(content) {';
const startIndex = bladeContent.indexOf(startMarker);
if (startIndex === -1) {
    throw new Error('Marker window.renderCopilotMarkdown not found in master.blade.php');
}

let braceCount = 0;
let endIndex = -1;
for (let i = startIndex; i < bladeContent.length; i++) {
    if (bladeContent[i] === '{') braceCount++;
    else if (bladeContent[i] === '}') {
        braceCount--;
        if (braceCount === 0) {
            endIndex = i + 1;
            break;
        }
    }
}
const funcCode = bladeContent.substring(startIndex, endIndex);

function createRenderer(env = {}) {
    const ctx = {
        window: { ...env },
        console: console
    };
    vm.createContext(ctx);
    vm.runInContext(funcCode, ctx);
    return ctx.window.renderCopilotMarkdown;
}

// -------------------------------------------------------------
// Core Fallback Parser Tests (Baseline Security)
// -------------------------------------------------------------

test('1. Valid WhatsApp HTTPS URL produces functional anchor tag', () => {
    const render = createRenderer({});
    const input = '[Chat WhatsApp](https://wa.me/6281234567890?text=Halo%20Supplier)';
    const html = render(input);

    assert.ok(html.includes('<a href="https://wa.me/6281234567890?text=Halo%20Supplier"'), 'Anchor href missing or incorrect');
    assert.ok(html.includes('Chat WhatsApp ↗</a>'), 'Link text missing');
    assert.ok(html.includes('target="_blank"'), 'target="_blank" missing');
    assert.ok(html.includes('rel="noopener noreferrer"'), 'rel="noopener noreferrer" missing');
});

test('2. Valid HTTP URL handled according to renderer policy', () => {
    const render = createRenderer({});
    const input = '[Dokumentasi API](http://api.kaching.id/docs)';
    const html = render(input);

    assert.ok(html.includes('<a href="http://api.kaching.id/docs"'), 'HTTP anchor href missing');
    assert.ok(html.includes('target="_blank"'), 'target="_blank" missing on HTTP link');
});

test('3. javascript: and data: schemes do NOT become active links', () => {
    const render = createRenderer({});
    const jsInput = '[Klik Di Sini](javascript:alert(document.domain))';
    const dataInput = '[Download Exploit](data:text/html,<script>alert(1)</script>)';
    const vbInput = '[VBScript Link](vbscript:msgbox(1))';

    const jsHtml = render(jsInput);
    const dataHtml = render(dataInput);
    const vbHtml = render(vbInput);

    assert.ok(!jsHtml.includes('<a href="javascript:'), 'javascript: scheme converted to anchor href');
    assert.ok(!jsHtml.includes('<a href='), 'javascript: produced anchor tag');
    assert.ok(jsHtml.includes('[Klik Di Sini](javascript:alert(document.domain))'), 'Original text preserved');

    assert.ok(!dataHtml.includes('<a href="data:'), 'data: scheme converted to anchor href');
    assert.ok(!dataHtml.includes('<a href='), 'data: produced anchor tag');

    assert.ok(!vbHtml.includes('<a href='), 'vbscript: produced anchor tag');
});

test('4. URL containing double quotes cannot inject HTML attributes (Attribute Breakout)', () => {
    const render = createRenderer({});
    const input = '[Exploit](https://wa.me/6281234567890"onmouseover="alert(1))';
    const html = render(input);

    assert.ok(!html.includes('<a href='), 'URL with double quote should fail to match regex and NOT create anchor tag');
    assert.ok(!/<[a-z]+[^>]*\sonmouseover=/i.test(html), 'Attribute onmouseover must not be injected into HTML tag');
    assert.ok(html.includes('[Exploit](https://wa.me/6281234567890"onmouseover="alert(1))'), 'Raw text rendered safely');
});

test('5. URL containing single quotes cannot inject HTML attributes', () => {
    const render = createRenderer({});
    const input = "[Exploit](https://wa.me/6281234567890'onclick='alert(1))";
    const html = render(input);

    assert.ok(!html.includes('<a href='), 'URL with single quote should fail to match regex');
    assert.ok(!/<[a-z]+[^>]*\sonclick=/i.test(html), 'Attribute onclick must not be injected into HTML tag');
});

test('6. Payloads like onmouseover, onerror, and tag injections are not executed', () => {
    const render = createRenderer({});
    const payload1 = '[Image Error](https://wa.me/test"onerror="alert(1)"foo=")';
    const payload2 = '[Body Onload](https://wa.me/test"onload="alert(1))';

    const html1 = render(payload1);
    const html2 = render(payload2);

    assert.ok(!html1.includes('<a href='), 'Payload 1 should not form anchor tag');
    assert.ok(!/<[a-z]+[^>]*\sonerror=/i.test(html1), 'Attribute onerror must not be injected');
    assert.ok(!html2.includes('<a href='), 'Payload 2 should not form anchor tag');
    assert.ok(!/<[a-z]+[^>]*\sonload=/i.test(html2), 'Attribute onload must not be injected');
});

test('7. Markdown labels containing HTML or special characters are safely escaped', () => {
    const render = createRenderer({});
    const htmlLabel = '[<script>alert("xss")</script>](https://wa.me/6281234567890)';
    const boldLabel = '[<b>Supplier Resmi</b>](https://wa.me/6281234567890)';
    const specialLabel = '[PT & CV "Berkah" <Utama>](https://wa.me/6281234567890)';

    const resHtml = render(htmlLabel);
    const resBold = render(boldLabel);
    const resSpecial = render(specialLabel);

    assert.ok(!resHtml.includes('<script>'), 'Unescaped script tag found in label output');
    assert.ok(resHtml.includes('&lt;script&gt;alert("xss")&lt;/script&gt;') || resHtml.includes('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;'), 'Script tag in label should be HTML escaped');

    assert.ok(!resBold.includes('<b>Supplier Resmi</b>'), 'Raw <b> tag should be escaped');
    assert.ok(resBold.includes('&lt;b&gt;Supplier Resmi&lt;/b&gt;'), 'Label bold tag was escaped');

    assert.ok(resSpecial.includes('PT &amp; CV'), '& in label escaped');
    assert.ok(resSpecial.includes('&lt;Utama&gt;'), '<Utama> in label escaped');
});

test('8. Malformed markdown or URL with forbidden characters fails safely', () => {
    const render = createRenderer({});
    const malformed1 = '[Incomplete Link(https://wa.me/6281234567890)';
    const malformed2 = '[(https://wa.me/6281234567890)';
    const malformed3 = '[Empty URL]()';
    const spaceInUrl = '[Spaces](https://wa.me/6281234567890 extra words)';

    assert.ok(!render(malformed1).includes('<a href='), 'Malformed 1 should not produce link');
    assert.ok(!render(malformed2).includes('<a href='), 'Malformed 2 should not produce link');
    assert.ok(!render(malformed3).includes('<a href='), 'Malformed 3 should not produce link');
    assert.ok(!render(spaceInUrl).includes('<a href='), 'Space in URL should not produce link');
});

test('9. Generated anchor tags always contain target="_blank" and rel="noopener noreferrer"', () => {
    const render = createRenderer({});
    const input = 'Hubungi kami di [WhatsApp Kasir](https://wa.me/628999888777).';
    const html = render(input);

    const anchorMatch = html.match(/<a\s+([^>]+)>/);
    assert.ok(anchorMatch, 'Anchor tag should be generated');
    const attrs = anchorMatch[1];
    assert.ok(attrs.includes('target="_blank"'), 'Attribute target="_blank" must be present');
    assert.ok(attrs.includes('rel="noopener noreferrer"'), 'Attribute rel="noopener noreferrer" must be present');
});

// -------------------------------------------------------------
// 4-Combination Security Matrix Tests (Phase B3.6.10J)
// -------------------------------------------------------------

test('10. Combination 1 (Marked + DOMPurify BOTH available): invokes Marked and enforces DOMPurify sanitize', () => {
    let markedCalled = false;
    let purifyCalled = false;

    const mockMarked = {
        parse: (content, opts) => {
            markedCalled = true;
            return `<p><a href="https://wa.me/6281234567890">WhatsApp</a><img src=x onerror=alert(1)></p>`;
        }
    };
    const mockDOMPurify = {
        sanitize: (rawHtml) => {
            purifyCalled = true;
            // Simulate DOMPurify stripping onerror
            return rawHtml.replace(/\s*onerror=[^\s>]+/gi, '');
        }
    };

    const render = createRenderer({ marked: mockMarked, DOMPurify: mockDOMPurify });
    const output = render('[WhatsApp](https://wa.me/6281234567890)');

    assert.ok(markedCalled, 'window.marked.parse should have been called');
    assert.ok(purifyCalled, 'window.DOMPurify.sanitize should have been called');
    assert.ok(output.includes('WhatsApp'), 'Output from CDN path returned');
    assert.ok(!output.includes('onerror'), 'DOMPurify sanitized rawHtml attributes');
});

test('11. Combination 2 (Marked available, DOMPurify MISSING): fails-closed to secure fallback parser, NEVER calling marked or returning raw HTML', () => {
    let markedCalled = false;
    const mockMarked = {
        parse: (content, opts) => {
            markedCalled = true;
            return `<p><img src=x onerror=alert(1)>Raw HTML Injection</p>`;
        }
    };

    // DOMPurify is NOT provided (undefined)
    const render = createRenderer({ marked: mockMarked });

    // Test with malicious input
    const maliciousInput = '<img src=x onerror=alert(1)> [Chat](https://wa.me/6281234567890)';
    const output = render(maliciousInput);

    // CRITICAL: marked.parse must NOT be called when DOMPurify is missing!
    assert.strictEqual(markedCalled, false, 'FAIL-CLOSED VIOLATION: window.marked.parse must NOT be called when DOMPurify is unavailable');

    // The raw HTML tag must NOT exist in the output; it must be entity-escaped
    assert.ok(!output.includes('<img src=x onerror='), 'Raw HTML tag with onerror must not be rendered');
    assert.ok(output.includes('&lt;img src=x onerror=alert(1)&gt;'), 'HTML tag must be entity escaped');

    // The legitimate WhatsApp link must still be formatted securely
    assert.ok(output.includes('<a href="https://wa.me/6281234567890" target="_blank" rel="noopener noreferrer"'), 'Legitimate link rendered securely');
});

test('12. Combination 3 (Marked MISSING, DOMPurify available): fails-closed to secure fallback parser', () => {
    let purifyCalled = false;
    const mockDOMPurify = {
        sanitize: (rawHtml) => {
            purifyCalled = true;
            return rawHtml;
        }
    };

    // marked is NOT provided (undefined)
    const render = createRenderer({ DOMPurify: mockDOMPurify });

    const input = '<script>alert(1)</script> [Chat](https://wa.me/6281234567890)';
    const output = render(input);

    assert.strictEqual(purifyCalled, false, 'DOMPurify should not be called since marked was absent');
    assert.ok(!output.includes('<script>'), 'Raw script tag must not exist in output');
    assert.ok(output.includes('&lt;script&gt;alert(1)&lt;/script&gt;'), 'Script tag must be entity-escaped');
    assert.ok(output.includes('<a href="https://wa.me/6281234567890" target="_blank" rel="noopener noreferrer"'), 'Legitimate link rendered securely');
});

test('13. Combination 4 (NEITHER Marked NOR DOMPurify available): pure zero-dependency fallback operates securely', () => {
    // Both marked and DOMPurify are absent
    const render = createRenderer({});

    const input = '<svg onload=alert(1)> [Chat](https://wa.me/6281234567890)';
    const output = render(input);

    assert.ok(!output.includes('<svg onload='), 'Raw svg tag must not exist in output');
    assert.ok(output.includes('&lt;svg onload=alert(1)&gt;'), 'SVG tag must be entity-escaped');
    assert.ok(output.includes('<a href="https://wa.me/6281234567890" target="_blank" rel="noopener noreferrer"'), 'Legitimate link rendered securely');
});

test('14. Exception handling in Marked or DOMPurify falls back safely to zero-dependency parser', () => {
    const throwingMarked = {
        parse: () => {
            throw new Error('Simulated Marked parser crash');
        }
    };
    const mockDOMPurify = {
        sanitize: (h) => h
    };

    const render = createRenderer({ marked: throwingMarked, DOMPurify: mockDOMPurify });
    const input = '**Important** [WhatsApp](https://wa.me/6281234567890)';
    const output = render(input);

    // Must catch exception and return safely parsed fallback output without crashing
    assert.ok(output.includes('<strong class="font-bold text-slate-900">Important</strong>'), 'Fallback formatted bold text');
    assert.ok(output.includes('<a href="https://wa.me/6281234567890" target="_blank" rel="noopener noreferrer"'), 'Fallback formatted WhatsApp link');
});

test('15. Security matrix against dangerous payload set across ALL 4 combinations', () => {
    const testEnvironments = [
        {
            name: 'Env 1: Marked + DOMPurify',
            env: {
                marked: { parse: (c) => c },
                DOMPurify: {
                    sanitize: (h) => h.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '')
                                      .replace(/\bon\w+\s*=[^>\s]+/gi, '')
                                      .replace(/href=["']?(javascript|data|vbscript):[^"'>\s]+/gi, 'href=""')
                }
            }
        },
        {
            name: 'Env 2: Marked only (No DOMPurify)',
            env: {
                marked: { parse: (c) => `<p>${c}</p>` }
            }
        },
        {
            name: 'Env 3: DOMPurify only (No Marked)',
            env: {
                DOMPurify: { sanitize: (h) => h }
            }
        },
        {
            name: 'Env 4: Zero CDN (Neither available)',
            env: {}
        }
    ];

    const dangerousPayloads = [
        '<script>alert("xss")</script>',
        '<img src=x onerror=alert(1)>',
        '<svg onload=alert(1)>',
        '<iframe src="javascript:alert(1)"></iframe>',
        '[Click Here](javascript:alert(1))',
        '[Download](data:text/html,<script>alert(1)</script>)',
        '[Breakout](https://wa.me/test"onmouseover="alert(1))'
    ];

    for (const { name, env } of testEnvironments) {
        const render = createRenderer(env);
        for (const payload of dangerousPayloads) {
            const result = render(payload);

            // Assert: No unescaped active script tags
            assert.ok(!/<script\b[^>]*>/i.test(result), `[${name}] Script tag leaked for payload: ${payload}`);

            // Assert: No active event handlers inside HTML tags
            assert.ok(!/<[a-z]+[^>]*\s+on\w+\s*=/i.test(result), `[${name}] Active event handler in tag leaked for payload: ${payload}`);

            // Assert: No active javascript: or data: in href attributes
            assert.ok(!/<a\s+[^>]*href=["']?(javascript|data|vbscript):/i.test(result), `[${name}] Dangerous URI scheme in anchor leaked for payload: ${payload}`);
        }
    }
});
