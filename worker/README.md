# Sitewise Worker

The inference half of the [Sitewise](../) WordPress plugin. The plugin compiles
your site into a corpus and pushes it here; this Worker answers visitor questions
grounded **only** in that corpus, using Cloudflare Workers AI.

This is the **BYO Cloudflare** path: you deploy it to your own account and pay
your own (tiny) inference cost. Nothing here is shared with anyone else.

## One-time setup

```bash
cd worker
npm install

# 1. Create the KV namespace and paste the returned id into wrangler.toml
npx wrangler kv namespace create SITEWISE_KV

# 2. Set the shared secret (any long random string).
#    Use the SAME value in WordPress: Settings → Sitewise → Shared secret.
npx wrangler secret put SITEWISE_SECRET

# 3. Deploy
npx wrangler deploy
```

`wrangler deploy` prints your Worker URL, e.g.
`https://sitewise.<your-subdomain>.workers.dev`. Paste that into
**Settings → Sitewise → Worker URL**, then click **Rebuild corpus now**.

## Endpoints

| Method | Path      | Auth                | Purpose                                  |
|--------|-----------|---------------------|------------------------------------------|
| GET    | `/health` | none                | Liveness check (the "Test connection").  |
| POST   | `/sync`   | `X-Sitewise-Secret` | Receive the corpus from the plugin.      |
| POST   | `/chat`   | CORS + rate limit   | Answer a visitor question.               |

## Configuration (wrangler.toml `[vars]`)

- `MODEL` — Workers AI model id. Default `@cf/meta/llama-3.1-8b-instruct`.
- `RATE_LIMIT` — messages per hour per IP. Default `20`.
- `SITEWISE_SECRET` — set as a **secret**, not a var.

## Notes

- **Stuff-the-prompt** mode only: the whole corpus is loaded into the system
  prompt per request. Fine for small/medium sites (< ~30K tokens). RAG +
  Vectorize is a later phase.
- CORS is locked per site to the origin recorded at `/sync` time.
- No chat content is logged — only per-IP rate counters in KV.
