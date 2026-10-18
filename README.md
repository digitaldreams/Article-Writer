# Article Writer

Symfony app that helps you plan a technical article step by step, then writes `article.md` with an LLM.
Every LLM step runs as a background Messenger job, so the app needs **web server + workers** running together.

Stack: PHP 8.4, Symfony 8.1, Symfony AI 0.14, PostgreSQL 16, Messenger (Doctrine transport), AssetMapper.

## 1. Requirements

- PHP 8.4 with `pdo_pgsql`, `ctype`, `iconv`
- Composer
- [Symfony CLI](https://symfony.com/download) (runs the server and the workers)
- Docker (PostgreSQL 16)
- [Ollama](https://ollama.com) for local models, or an OpenAI / Anthropic / Gemini API key

## 2. Install

```bash
composer install
symfony console importmap:install
```

## 3. Database

```bash
docker compose up -d
symfony console doctrine:migrations:migrate -n
symfony console doctrine:fixtures:load -n     # login: author@example.com / secret
```

Use `symfony console`, not `bin/console`: Docker maps PostgreSQL to a random host port, and the Symfony CLI
injects the matching `DATABASE_URL`. With your own PostgreSQL, set `DATABASE_URL` in `.env.local` instead.

## 4. Configure `.env.local`

`.env` holds the committed defaults. Create `.env.local` for secrets and your model choice:

```dotenv
APP_SECRET=change-me-to-a-random-string

# Local models (Ollama)
OLLAMA_ENDPOINT=http://127.0.0.1:11434
AI_PRIMARY_PLATFORM=ollama
AI_PRIMARY_MODEL=llama3.1
AI_SECONDARY_PLATFORM=ollama
AI_SECONDARY_MODEL=llama3.1
AI_TITLE_PLATFORM=ollama
AI_TITLE_MODEL=llama3.1
AI_POSTER_PLATFORM=ollama
AI_POSTER_MODEL=llama3.1
AI_POSTER_IMAGE_MODEL=

# Hosted models: set the key and point a role at it, e.g. AI_TITLE_PLATFORM=openai
OPENAI_API_KEY=
ANTHROPIC_API_KEY=
GEMINI_API_KEY=
```

| Role | Used for |
|---|---|
| `AI_PRIMARY_*` | Questions, outline, article, review, rewrite |
| `AI_SECONDARY_*` | Plan title, reference digests, article import |
| `AI_TITLE_*` | Title and share suggestions |
| `AI_POSTER_*` | Poster ideas |
| `AI_POSTER_IMAGE_MODEL` | Poster picture, on the poster platform. Empty = no image button |

Platforms: `ollama`, `openai`, `anthropic`, `gemini`. Articles are written to `ARTICLES_DIR` (default `var/articles`).

Using Ollama? Pull the models first, e.g. `ollama pull llama3.1`.

## 5. Run

```bash
symfony server:start -d
```

This starts the web server on 127.0.0.1 plus two workers from `.symfony.local.yaml`:

| Worker | Consumes |
|---|---|
| `llm` | `llm_primary`, `llm_secondary` (all LLM calls) |
| `io` | `io` (file imports, fetching reference pages) |

To run the workers yourself instead (one terminal each):

```bash
symfony console messenger:consume llm_primary llm_secondary -vv
```

```bash
symfony console messenger:consume io -vv
```

Open the printed URL, log in, fill your profile at `/setup`, then start a plan at `/plans`.

Check the workers: `symfony server:status`. Watch the logs: `symfony server:log`.

## 6. After changing config

| You changed | Do this |
|---|---|
| Any `AI_*_PLATFORM` | `symfony console cache:clear`, then `symfony server:stop && symfony server:start -d` |
| A model, key, or `prompts/*.md` | Restart the server, so the workers reload |
| An entity | `symfony console make:migration && symfony console doctrine:migrations:migrate` |

## 7. Tests

```bash
php bin/phpunit                  # uses .env.test; never calls a real model
vendor/bin/phpstan analyse
```

## Troubleshooting

- **Job card stays queued / "stalled" warning:** workers are not running. Run `symfony server:start -d` and check `symfony server:status`.
- **Job failed with a connection error:** Ollama is not running, or the model is not pulled. Fix it, then press Retry on the card.
- **Database connection refused:** run `docker compose up -d`, and use `symfony console` instead of `bin/console`.
