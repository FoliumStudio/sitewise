/**
 * Sitewise Worker — the inference half of the plugin.
 *
 * Endpoints:
 *   POST /sync    Receive a site's corpus from the WP plugin (auth: shared secret).
 *   POST /chat    Answer a visitor question, grounded ONLY in that site's corpus.
 *   GET  /health  Liveness check for the plugin's "Test connection" button.
 *
 * MVP design (matches CONCEPT.md):
 *   - "Stuff-the-prompt": the whole corpus goes into the system prompt. No RAG.
 *   - One model: Workers AI Llama 3.1 8B (swap via env.MODEL).
 *   - Per-site config + corpus live in KV, keyed by site_key.
 *   - CORS is locked to each site's registered origin.
 *   - IP rate limiting via a KV counter.
 *
 * Bindings (see wrangler.toml):
 *   env.AI               Workers AI binding.
 *   env.SITEWISE_KV      KV namespace for corpus + config + rate counters.
 *   env.SITEWISE_SECRET  Shared secret; must match the WP plugin's setting.
 *   env.MODEL            (optional) model id override.
 *   env.RATE_LIMIT       (optional) messages per hour per IP (default 20).
 */

const DEFAULT_MODEL = '@cf/meta/llama-3.1-8b-instruct';
const MAX_MESSAGE_CHARS = 1500;
const MAX_CORPUS_CHARS = 120000; // ~30K tokens; above this, recommend RAG (later).

export default {
	async fetch(request, env) {
		const url = new URL(request.url);
		const path = url.pathname.replace(/\/+$/, '') || '/';

		if (request.method === 'OPTIONS') {
			return handlePreflight(request, env);
		}

		try {
			if (path === '/health') {
				return json({ ok: true, service: 'sitewise', time: Date.now() });
			}
			if (path === '/sync' && request.method === 'POST') {
				return await handleSync(request, env);
			}
			if (path === '/chat' && request.method === 'POST') {
				return await handleChat(request, env);
			}
			return json({ error: 'Not found' }, 404);
		} catch (err) {
			return json({ error: 'Internal error', detail: String(err && err.message || err) }, 500);
		}
	},
};

/* --------------------------------------------------------------------------- */

async function handleSync(request, env) {
	const secret = request.headers.get('X-Sitewise-Secret') || '';
	if (!env.SITEWISE_SECRET || secret !== env.SITEWISE_SECRET) {
		return json({ error: 'Unauthorized' }, 401);
	}

	const body = await request.json().catch(() => null);
	if (!body || !body.site_key) {
		return json({ error: 'Missing site_key' }, 400);
	}

	const siteKey = String(body.site_key).slice(0, 200);
	let full = String(body.full || '');
	if (full.length > MAX_CORPUS_CHARS) {
		full = full.slice(0, MAX_CORPUS_CHARS);
	}

	const meta = {
		site_name: String(body.site_name || siteKey),
		origin: normaliseOrigin(body.origin || ''),
		contact: String(body.contact || ''),
		map_url: String(body.map_url || ''),
		full_url: String(body.full_url || ''),
		updated: Date.now(),
		bytes: full.length,
	};

	await env.SITEWISE_KV.put(`corpus:${siteKey}`, full);
	await env.SITEWISE_KV.put(`meta:${siteKey}`, JSON.stringify(meta));

	return json({ ok: true, bytes: full.length, site_key: siteKey });
}

async function handleChat(request, env) {
	const body = await request.json().catch(() => null);
	if (!body || !body.site_key || !body.message) {
		return json({ error: 'Missing site_key or message' }, 400);
	}

	const siteKey = String(body.site_key).slice(0, 200);
	const metaRaw = await env.SITEWISE_KV.get(`meta:${siteKey}`);
	const meta = metaRaw ? JSON.parse(metaRaw) : null;

	// CORS: only answer for the site's registered origin.
	const origin = request.headers.get('Origin') || '';
	const allowOrigin = meta && meta.origin ? meta.origin : '';
	if (allowOrigin && origin && normaliseOrigin(origin) !== allowOrigin) {
		return json({ error: 'Origin not allowed' }, 403);
	}

	// Rate limit by IP.
	const ip = request.headers.get('CF-Connecting-IP') || 'unknown';
	const limit = parseInt(env.RATE_LIMIT, 10) || 20;
	const allowed = await checkRate(env, siteKey, ip, limit);
	if (!allowed) {
		return cors(json({ answer: 'You have reached the message limit for now. Please try again later.' }), origin, allowOrigin);
	}

	const message = String(body.message).slice(0, MAX_MESSAGE_CHARS);
	const corpus = (await env.SITEWISE_KV.get(`corpus:${siteKey}`)) || '';
	if (!corpus) {
		return cors(json({ answer: 'This assistant has not been set up yet.' }), origin, allowOrigin);
	}

	const siteName = meta ? meta.site_name : 'this site';
	const contact = meta && meta.contact ? meta.contact : '';

	const system = buildSystemPrompt(siteName, contact, corpus);
	const history = Array.isArray(body.history) ? body.history.slice(-8) : [];

	const messages = [{ role: 'system', content: system }];
	for (const h of history) {
		if (h && (h.role === 'user' || h.role === 'assistant') && typeof h.content === 'string') {
			messages.push({ role: h.role, content: h.content.slice(0, MAX_MESSAGE_CHARS) });
		}
	}
	messages.push({ role: 'user', content: message });

	const model = env.MODEL || DEFAULT_MODEL;
	const result = await env.AI.run(model, { messages, max_tokens: 512 });
	let answer = (result && (result.response || result.text)) || '';

	// Detect the "not covered by the corpus" sentinel the system prompt asks for.
	// When present (or the model returned nothing usable), flag a fallback so the
	// widget can offer a contact / call-back handoff instead of a dead end.
	const fallback = !answer.trim() || /\[\[\s*NO_ANSWER\s*\]\]/i.test(answer);
	if (fallback) {
		answer = contact
			? `I don't have that in ${siteName}'s information. I can pass your details on and arrange a call back — or you can reach the team directly.`
			: `I don't have that in ${siteName}'s information, but I can arrange a call back. Leave your details below.`;
	} else {
		answer = answer.replace(/\[\[\s*NO_ANSWER\s*\]\]/gi, '').trim();
	}

	return cors(json({ answer, fallback }), origin, allowOrigin);
}

/* --------------------------------------------------------------------------- */

function buildSystemPrompt(siteName, contact, corpus) {
	return [
		`You are the on-site assistant for ${siteName}. Answer ONLY from the corpus below.`,
		'If the answer is not contained in the corpus, reply with exactly this and nothing else: [[NO_ANSWER]]',
		`Do not invent details. Do not compare ${siteName} to competitors.`,
		'Keep answers short (2-4 sentences) unless explicitly asked for more.',
		'',
		'CORPUS:',
		corpus,
	].join('\n');
}

async function checkRate(env, siteKey, ip, limit) {
	const hour = Math.floor(Date.now() / 3600000);
	const key = `rate:${siteKey}:${ip}:${hour}`;
	const current = parseInt((await env.SITEWISE_KV.get(key)) || '0', 10);
	if (current >= limit) {
		return false;
	}
	// KV TTL min is 60s; 3700s covers the rest of the hour comfortably.
	await env.SITEWISE_KV.put(key, String(current + 1), { expirationTtl: 3700 });
	return true;
}

function normaliseOrigin(value) {
	try {
		const u = new URL(value);
		return `${u.protocol}//${u.host}`;
	} catch (e) {
		return String(value || '').replace(/\/+$/, '');
	}
}

/* --------------------------------------------------------------------------- */

function json(obj, status = 200) {
	return new Response(JSON.stringify(obj), {
		status,
		headers: { 'Content-Type': 'application/json' },
	});
}

function cors(response, requestOrigin, allowOrigin) {
	const headers = new Headers(response.headers);
	const allow = allowOrigin || requestOrigin || '*';
	headers.set('Access-Control-Allow-Origin', allow);
	headers.set('Vary', 'Origin');
	return new Response(response.body, { status: response.status, headers });
}

function handlePreflight(request, env) {
	const origin = request.headers.get('Origin') || '*';
	return new Response(null, {
		status: 204,
		headers: {
			'Access-Control-Allow-Origin': origin,
			'Access-Control-Allow-Methods': 'POST, GET, OPTIONS',
			'Access-Control-Allow-Headers': 'Content-Type, X-Sitewise-Secret',
			'Access-Control-Max-Age': '86400',
			'Vary': 'Origin',
		},
	});
}
