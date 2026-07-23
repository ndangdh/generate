# Identity Generator

A responsive, browser-based generator for realistic U.S. test identity records. Generate up to 20 records at once and click any field to copy its value.

## Live Demo

https://ndangdh.github.io/generate/

## Features

- Generates names, usernames, email addresses, phone numbers, and U.S. addresses
- Supports 1 to 20 records per request
- Copy individual fields or an entire formatted record with visual and accessible feedback
- Static client-side password gate
- Persistent Light and Dark themes
- Responsive layout for desktop and mobile
- Runs entirely in the browser with no server-side runtime or external dependencies

## Run Locally

Serve the repository with any static web server, then open it in a browser:

```bash
python -m http.server 8000
```

Visit `http://localhost:8000/`. Opening `index.html` directly is not supported because browsers restrict loading the local dataset files.

## Files

- `index.html` - Interface and generator logic
- `name.txt` - Name data
- `domain.txt` - Email domain data
- `phone.txt` - State area-code data
- `uszips.txt` - U.S. ZIP, city, and state data

GitHub Pages deploys directly from the root of the `main` branch.