=== Sitewise ===
Contributors: pigeonhut
Tags: chatbot, ai, customer support, call back, assistant
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 4.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add an on-page assistant that answers only from your own content, plus the classic request-a-call-back form. Grounded, cheap, no SaaS lock-in.

== Description ==

**Sitewise turns your existing pages into a chat assistant that answers visitors using only your own content** — no hallucinated competitors, no off-brand advice. It also includes the original request-a-call-back form, so visitors who would rather talk can leave their number.

The plugin compiles your published content into a small knowledge corpus (the `llms.txt` / `llms-full.txt` pattern) and keeps it in sync as you publish and edit. A lightweight Cloudflare Worker answers questions strictly from that corpus, and falls back to your contact page when it does not know.

> **Note:** the grounded chat assistant is in preview while we finish the hosted Worker. Out of the box the public floating widget is the **request-a-call-back form** (no Worker required). Once you connect a Worker and switch the front-end to chat mode, the same launcher becomes the assistant.

= What you get =
* **Request-a-call-back widget** — a floating launcher (or inline `[sitewise]` shortcode) that lets visitors leave their number; submissions are stored in your dashboard and emailed to you. **This is the default front-end widget today.**
* **Grounded chat assistant (preview)** — once a Cloudflare Worker is connected, the launcher answers from your pages only.
* **Self-maintaining corpus** — rebuilt automatically when you publish, edit, trash, or delete content.
* **Public `llms.txt` files** — so other AI agents can read your site too.
* **Hand-curation where it counts** — an orientation block and an FAQ block you write once, plus a per-page "AI summary" box.
* **Request-a-call-back form** — the original Call-Me-Back feature, rewritten clean. Use the `[sitewise_callback]` shortcode or the sidebar widget; submissions are stored in your dashboard and emailed to you.

= Two ways to run it =
* **BYO Cloudflare (free):** deploy the included Worker to your own Cloudflare account and pay your own (tiny) inference cost. Cleanest privacy story.
* **Hosted (optional):** paste a site key from a Sitewise account and let us host the Worker, with analytics and stronger models.

= Privacy =
By default the Worker logs no chat content — only message counts for rate limiting. Your corpus is built from already-public pages.

== A note on this update (3.x → 4.0) ==

This plugin began life as **Call me back widget**. Version 4.0 keeps that call-back form as a built-in feature and adds the Sitewise assistant as the new headline capability. **Existing call-back users keep their widget** — enable it under **Settings → Sitewise → Call-back widget** — and gain the assistant on top. See the upgrade notice below.

== Installation ==

1. Install and activate the plugin.
2. Go to **Sitewise** in the admin menu.
3. **Call-back form (on by default):** the floating launcher already shows a request-a-call-back form site-wide. You can also drop the inline `[sitewise]` or `[sitewise_callback]` shortcode on a page, or add the sidebar widget. Submissions appear in your dashboard and are emailed to you.
4. **Chat assistant (preview):** deploy the bundled Cloudflare Worker (see the `worker/` folder on GitHub), paste its URL and shared secret, click **Rebuild corpus now**, then switch the front-end to chat mode — the same launcher becomes the assistant.

== Frequently asked questions ==

= Does the assistant make things up? =
No — it is instructed to answer only from your compiled corpus and to send visitors to your contact page when a question is not covered.

= Do I need an OpenAI key? =
No. The default Worker uses Cloudflare Workers AI. Claude and other providers are pluggable later.

= I only want the call-back form, not the chatbot. =
Leave the chatbot disabled under Settings → Sitewise; the call-back form works on its own, exactly as before.

= Where is my content sent? =
In BYO mode, only to your own Cloudflare Worker. The corpus is built from public pages and stored in your uploads directory.

== Screenshots ==

1. The request-a-call-back widget — a floating launcher that opens an on-brand call-back form on any page.
2. The Sitewise dashboard in the Folium admin — corpus status, recent questions, and sync at a glance.
3. The Widget screen — skin the launcher (colour, position, copy), toggle the site-wide bubble, with a live preview.
4. Call-back requests collected in your dashboard and emailed to you.
5. The grounded chat assistant (preview) — answers visitors only from your own content.

== Upgrade Notice ==

= 4.0.0 =
Major update: Call me back widget becomes Sitewise. Your call-back form remains available, and a grounded chat assistant is added. Review your settings after updating.

== Changelog ==

= 4.0.0 =
* NEW: the public floating widget is the request-a-call-back form by default; the grounded chat assistant ships in preview and turns on once a Worker is connected.
* NEW: Sitewise grounded chat assistant — answers visitors from your own content via a Cloudflare Worker.
* NEW: automatic `llms.txt` + `llms-full.txt` corpus generation, rebuilt on content changes.
* NEW: orientation + FAQ curation blocks and a per-page "AI summary" meta box.
* NEW: floating chat launcher, `[sitewise]` inline shortcode, BYO/hosted modes.
* CHANGE: the call-back feature is rewritten clean — submissions are now stored as a dashboard list and emailed to the admin, replacing the legacy custom tables.
* CHANGE: removed the embedded reCAPTCHA library in favour of a nonce + honeypot; bundled BebasNeue font and colpick picker dropped.
* FIX: removed `error_reporting(0)` and unserialized-option patterns from the 3.x codebase.
* Requires PHP 7.4+ and WordPress 6.4+.

= 3.4.1 =
* Legacy "Call me back widget" release (2017).
