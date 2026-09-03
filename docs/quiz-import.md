# Quiz content import / export

## ZIP pack (quizzes + media)

On **Content**, select one or more quizzes and **Export ZIP**. The archive contains:

```
family-quiz-content.json
media/<file>.jpg   (only files referenced in those quizzes)
```

Hosted image URLs in HTML are rewritten to pack-relative `media/<filename>` so the pack is portable across projects and environments.

**Import ZIP** on the same page (project must be SETUP or TEST) copies quizzes into the current project, stores files under that project’s `uploads/` directory, and rewrites HTML to the new media URLs. New quiz IDs are generated; participant answers are not included.

## JSON (new quizzes, no media files)

Use this to add quizzes from a text file or an LLM. Import via **Import JSON/ZIP** or `POST /api/admin/projects/{id}/quizzes/import-pack` with `Content-Type: application/json`.

Example: [`docs/examples/quizzes-import.json`](examples/quizzes-import.json)

### Schema

```json
{
  "format": "family-quiz-content",
  "version": 1,
  "quizzes": [
    {
      "title": "string (required)",
      "description_html": "string, HTML",
      "explanation_html": "string, HTML",
      "points": 1,
      "shuffle_options": true,
      "options": [
        {
          "label_html": "string, HTML",
          "is_correct": true,
          "feedback_html": "string, HTML, optional"
        }
      ]
    }
  ]
}
```

Rules:

- `quizzes` is a non-empty array. A single quiz object (no wrapper) is also accepted.
- Each quiz has **at most 4** options; fewer than 4 are padded with empty options.
- **Exactly one** option should have `"is_correct": true`. If none is marked, the first option is treated as correct.
- HTML is sanitized the same way as the admin editor (images, basic formatting, allowlisted embeds).
- For images, either use an `https://…` URL in `src`, or import a ZIP pack. JSON import does **not** upload binary files.

`format` and `version` are optional on import (they are written on ZIP export).

### cURL

```bash
curl -s -X POST -H 'Content-Type: application/json' --cookie 'fq_admin=…' \
  --data @docs/examples/quizzes-import.json \
  'https://www.kunstman.net/familyquiz/api/admin/projects/PROJECT_ID/quizzes/import-pack'
```
