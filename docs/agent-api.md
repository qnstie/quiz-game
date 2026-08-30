# Agent API (LLM / automation)

Machine-friendly JSON API for creating and editing quiz content. Intended for language models and scripts — not linked from the admin UI.

## Auth

Uses the same secret as magic admin login (`admin_magic_token` in `server/config.php`), or `agent_api_token` if you set one separately.

Send one of:

```http
Authorization: Bearer YOUR_TOKEN
```

```http
X-Agent-Token: YOUR_TOKEN
```

```http
GET /api/agent/projects?t=YOUR_TOKEN
```

Token must be at least 32 characters and not left as the `GENERATE_WITH_…` placeholder.

## Base URL

Local: `http://127.0.0.1:8080` (or Vite proxy at `/api/agent`).

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/agent` | Help + schema (authenticated) |
| `GET` | `/api/agent/projects` | List projects |
| `GET` | `/api/agent/projects/{id}` | Project detail |
| `GET` | `/api/agent/projects/{id}/quizzes` | List quizzes (`?incomplete=1` filters) |
| `POST` | `/api/agent/projects/{id}/quizzes` | Create quiz (optional full body) |
| `GET` | `/api/agent/quizzes/{quizId}` | Get quiz + options |
| `PATCH` | `/api/agent/quizzes/{quizId}` | Update fields and/or merge options |
| `PUT` | `/api/agent/quizzes/{quizId}/options` | Replace all 4 options |
| `GET` | `/api/agent/projects/{id}/media` | List media |
| `POST` | `/api/agent/projects/{id}/media` | Upload `file` (multipart) |

Writes require project state **SETUP** or **TEST** (otherwise `423 LOCKED`).

Each quiz has **exactly 4 options** and **exactly one** `is_correct: true`. `feedback_html` is optional.

## Examples

```bash
TOKEN='…'
API='http://127.0.0.1:8080'

# Discover
curl -s -H "Authorization: Bearer $TOKEN" "$API/api/agent" | jq

# List incomplete quizzes
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/api/agent/projects/PROJECT_ID/quizzes?incomplete=1" | jq

# Create a full question
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{
    "title": "Who invented the telephone?",
    "description_html": "<p>Pick the best answer.</p>",
    "explanation_html": "<p>Bell, 1876.</p>",
    "options": [
      {"label_html": "Alexander Graham Bell", "is_correct": true, "feedback_html": "Correct!"},
      {"label_html": "Thomas Edison", "is_correct": false},
      {"label_html": "Nikola Tesla", "is_correct": false},
      {"label_html": "Guglielmo Marconi", "is_correct": false}
    ]
  }' \
  "$API/api/agent/projects/PROJECT_ID/quizzes" | jq

# Fill missing description on an existing quiz
curl -s -X PATCH -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"description_html":"<p>Updated prompt with <img src=\"/media/PROJECT_ID/….jpg\"></p>"}' \
  "$API/api/agent/quizzes/QUIZ_ID" | jq

# Upload media then embed the returned url in HTML
curl -s -X POST -H "Authorization: Bearer $TOKEN" \
  -F "file=@photo.jpg" \
  "$API/api/agent/projects/PROJECT_ID/media" | jq
```

Rate limit: 180 requests / hour / IP.
